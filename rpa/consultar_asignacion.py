"""Loop principal del RPA: recorre las cuentas pendientes de la asignacion
activa, consulta cada una en mibait, guarda el snapshot, infiere pagos y
actualiza el estado. Throttling por franja horaria y backoff ante 403.

Uso: consultar_asignacion.py [limite_cuentas]
"""
import argparse
import time
from datetime import datetime, timedelta

from playwright.sync_api import sync_playwright

import db
import mibait
import repos


# ---------- throttling por franja ----------

def _parse_hhmm(s: str):
    h, m = s.split(":")
    return int(h) * 60 + int(m)


def _en_franja_noche(ahora: datetime) -> bool:
    ini = _parse_hhmm(db.get_config("franja_noche_inicio", "23:00"))
    fin = _parse_hhmm(db.get_config("franja_noche_fin", "06:00"))
    actual = ahora.hour * 60 + ahora.minute
    if ini <= fin:
        return ini <= actual < fin
    return actual >= ini or actual < fin  # cruza medianoche


def pausa_actual() -> float:
    if _en_franja_noche(datetime.now()):
        return float(db.get_config("rpa_pausa_noche_seg", "0.8"))
    return float(db.get_config("rpa_pausa_dia_seg", "2.5"))


# ---------- inferencia ----------

def procesar(cur, c, r, ahora):
    """Escribe el snapshot y deriva estatus/eventos comparando con el estado
    previo de la cuenta."""
    ac_id = c["id"]
    if r.desenlace == "al_corriente":
        saldo_nuevo = 0.0
    elif r.desenlace == "con_saldo":
        saldo_nuevo = float(r.total if r.total is not None else (r.monto_plan or 0))
    else:
        saldo_nuevo = None

    repos.insertar_consulta(cur, ac_id, ahora, r, saldo_nuevo)

    # Lineas no cobrables o consulta fallida.
    if r.desenlace in ("prepago", "no_bait"):
        repos.actualizar_cuenta(cur, ac_id, tipo_linea=r.desenlace, cerrada=1,
                                ultima_consulta_at=ahora, updated_at=ahora)
        return
    if r.desenlace == "error":
        repos.actualizar_cuenta(cur, ac_id, ultima_consulta_at=ahora,
                                updated_at=ahora)
        return

    saldo_ref = c["saldo_referencia"]
    saldo_prev = None if c["saldo_actual"] is None else float(c["saldo_actual"])
    estatus_prev = c["estatus_cobranza"] or "con_adeudo"
    primera = saldo_ref is None
    if primera:
        saldo_ref = saldo_nuevo

    if r.desenlace == "al_corriente":
        # Saldo en cero: pago total si antes habia saldo conocido.
        if not primera and saldo_prev and saldo_prev > 0:
            repos.insertar_evento(cur, ac_id, "pago_total", saldo_prev,
                                  saldo_prev, 0.0, c["ultima_consulta_at"], ahora)
        repos.actualizar_cuenta(
            cur, ac_id, saldo_referencia=saldo_ref, saldo_actual=0.0,
            estatus_cobranza="pago_total", tipo_linea="bait", cerrada=1,
            fecha_pago_inferida=ahora.date(), ultima_consulta_at=ahora,
            updated_at=ahora)
        return

    # con_saldo
    estatus = estatus_prev
    if primera:
        estatus = "con_adeudo"
    elif saldo_prev is not None and saldo_nuevo < saldo_prev:
        repos.insertar_evento(cur, ac_id, "pago_parcial", saldo_prev - saldo_nuevo,
                              saldo_prev, saldo_nuevo, c["ultima_consulta_at"], ahora)
        estatus = "pago_parcial"
    repos.actualizar_cuenta(
        cur, ac_id, saldo_referencia=saldo_ref, saldo_actual=saldo_nuevo,
        estatus_cobranza=estatus, tipo_linea="bait", ultima_consulta_at=ahora,
        updated_at=ahora)


# ---------- orquestador ----------

def _parse_args():
    p = argparse.ArgumentParser(description="RPA de consulta de cuentas.")
    p.add_argument("limite", nargs="?", type=int, default=None,
                   help="limite global de cuentas (opcional)")
    p.add_argument("--limite", dest="limite_kw", type=int, default=None,
                   help="igual que el posicional, para invocar desde el panel")
    p.add_argument("--worker", type=int, default=0, help="indice de este worker (0..N-1)")
    p.add_argument("--workers", type=int, default=1, help="total de workers en paralelo")
    p.add_argument("--once", action="store_true",
                   help="una sola pasada en vez del modo continuo 24/7")
    a = p.parse_args()
    a.limite = a.limite_kw if a.limite_kw is not None else a.limite
    a.workers = max(1, a.workers)
    a.worker = max(0, min(a.worker, a.workers - 1))
    return a


def _seleccionar(cur, asig_id, worker, workers, ventana_h, limite):
    """Cuentas vencidas que le tocan a este worker. Reparto estable por id de
    cuenta (id % workers == worker), para que en modo continuo una cuenta caiga
    siempre en el mismo worker y dos procesos no se pisen."""
    corte = datetime.now() - timedelta(hours=ventana_h)
    cuentas = repos.cuentas_due(cur, asig_id, corte)
    if limite:
        cuentas = cuentas[:limite]
    if workers > 1:
        cuentas = [c for c in cuentas if c["id"] % workers == worker]
    return cuentas


def _consultar_lote(cur, page, cuentas, etiqueta):
    """Consulta una lista de cuentas reutilizando la misma page. Devuelve
    (exitos, errores)."""
    exitos = errores = 0
    n = len(cuentas)
    for i, c in enumerate(cuentas, 1):
        ahora = datetime.now()
        try:
            mibait.abrir_formulario(page)
            r = mibait.consultar(page, c["numero"])
        except Exception as e:  # noqa: BLE001
            r = mibait.Resultado(numero=c["numero"], desenlace="error",
                                 error=str(e)[:200])
        try:
            procesar(cur, c, r, ahora)  # autocommit: cada statement se confirma
        except Exception as e:  # noqa: BLE001
            errores += 1
            print(f"  [{etiqueta} {i}/{n}] {c['numero']} -> ERROR DB: {e}")
            continue

        if r.desenlace == "error":
            errores += 1
        else:
            exitos += 1
        extra = ""
        if r.desenlace == "con_saldo":
            extra = f" saldo={r.total}"
        elif r.desenlace == "al_corriente":
            extra = " saldo=0"
        print(f"  [{etiqueta} {i}/{n}] {c['numero']} -> {r.desenlace}{extra}")

        if r.error == "403":
            print("  403 detectado: backoff 60s")
            time.sleep(60)
        time.sleep(pausa_actual())
    return exitos, errores


def main():
    args = _parse_args()
    worker, workers = args.worker, args.workers
    headless = db.get_config("rpa_headless", "false") == "true"
    ventana_h = float(db.get_config("rpa_ventana_horas", "24") or "24")
    idle_seg = float(db.get_config("rpa_idle_seg", "60") or "60")

    conn = db.connect()
    cur = conn.cursor()
    asig = repos.asignacion_activa(cur)
    if not asig:
        print("No hay asignacion activa.")
        conn.close()
        return

    etiqueta = f"w{worker + 1}/{workers}" if workers > 1 else "w1"
    modo = "una pasada" if args.once else "continuo 24/7"
    print(f"Asignacion '{asig['nombre']}' (id={asig['id']}) | {etiqueta} | "
          f"modo {modo} | ventana {ventana_h:.0f}h")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=headless)
        page = browser.new_context(viewport={"width": 1100, "height": 800}).new_page()
        while True:
            cuentas = _seleccionar(cur, asig["id"], worker, workers, ventana_h, args.limite)
            if not cuentas:
                if args.once:
                    print(f"{etiqueta}: no hay cuentas vencidas. Fin.")
                    break
                print(f"{etiqueta}: sin cuentas vencidas; espera {idle_seg:.0f}s")
                time.sleep(idle_seg)
                continue

            inicio = datetime.now()
            # Solo el worker 0 registra la corrida (bitacora del lote).
            corrida_id = None
            if worker == 0:
                corrida_id = repos.crear_corrida(cur, asig["id"], inicio, concurrencia=workers)
            print(f"{etiqueta}: lote de {len(cuentas)} cuentas vencidas")
            exitos, errores = _consultar_lote(cur, page, cuentas, etiqueta)
            fin = datetime.now()
            if corrida_id:
                repos.cerrar_corrida(cur, corrida_id, fin, len(cuentas), exitos,
                                     errores, "completada")
            print(f"{etiqueta}: lote {exitos} ok, {errores} err, "
                  f"{(fin - inicio).total_seconds():.0f}s")
            if args.once:
                break
        browser.close()
    conn.close()


if __name__ == "__main__":
    main()
