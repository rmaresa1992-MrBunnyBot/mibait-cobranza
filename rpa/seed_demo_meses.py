"""Escenario de DEMOSTRACION para el tablero de metricas con proyeccion:
tres carteras-mes (diciembre, enero, febrero) con curvas de recuperacion.
Enero es el mejor mes (75%); febrero esta en curso (datos hasta el dia 12) y
a ritmo bajo, para ver la brecha contra la proyeccion del mejor mes.
Borrable con --borrar. No toca la cartera real activa."""
from datetime import date, datetime, timedelta

import db

ADEUDO = 199
TOTAL = 100  # cuentas por cartera

# (offset_dia_desde_carga, cuantas pagan ese dia)
PERFIL = {
    "Demo · Diciembre 2025": (date(2025, 12, 1),
        [(2, 8), (5, 7), (8, 6), (11, 6), (14, 5), (17, 4), (20, 4), (23, 4), (26, 3), (29, 3)]),  # 50%
    "Demo · Enero 2026": (date(2026, 1, 1),
        [(1, 14), (3, 12), (5, 10), (8, 9), (11, 8), (14, 7), (18, 6), (22, 4), (26, 3), (30, 2)]),  # 75% (mejor)
    "Demo · Febrero 2026": (date(2026, 2, 1),
        [(2, 4), (4, 4), (6, 3), (8, 3), (10, 3), (12, 3)]),  # 20% al dia 12 (en curso)
}


def _num_id(cur, numero, ahora):
    row = cur.execute("SELECT id FROM numeros WHERE numero=?", numero).fetchone()
    if row:
        return row[0]
    return cur.execute(
        "INSERT INTO numeros (numero, primera_vez_visto, veces_asignado, "
        "created_at, updated_at) OUTPUT INSERTED.id VALUES (?,?,?,?,?)",
        numero, ahora, 1, ahora, ahora).fetchone()[0]


def _cuenta(cur, asig_id, numero, ahora, carga, fecha_pago):
    nid = _num_id(cur, numero, ahora)
    pagado = fecha_pago is not None
    ac_id = cur.execute(
        "INSERT INTO asignacion_cuentas (asignacion_id, numero_id, numero, "
        "fecha_entrega, estatus_carga, saldo_referencia, saldo_actual, "
        "estatus_cobranza, tipo_linea, cerrada, fecha_pago_inferida, "
        "monto_emision, created_at, updated_at) "
        "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        asig_id, nid, numero, carga, "con_adeudo", ADEUDO, 0 if pagado else ADEUDO,
        "pago_total" if pagado else "con_adeudo", "bait", 1 if pagado else 0,
        fecha_pago, ADEUDO, ahora, ahora).fetchone()[0]
    if pagado:
        det = datetime(fecha_pago.year, fecha_pago.month, fecha_pago.day, 3, 0, 0)
        cur.execute(
            "INSERT INTO eventos_pago (asignacion_cuenta_id, tipo, monto_pagado, "
            "saldo_antes, saldo_despues, fecha_deteccion, created_at, updated_at) "
            "VALUES (?,?,?,?,?,?,?,?)",
            ac_id, "pago_total", ADEUDO, ADEUDO, 0, det, det, det)


def main():
    ahora = datetime.now()
    seq = 7100000000
    with db.cursor() as cur:
        for nombre, (carga, perfil) in PERFIL.items():
            asig_id = cur.execute(
                "INSERT INTO asignaciones (nombre, fecha_carga, activa, estado, "
                "tipo_origen, total_cuentas, created_at, updated_at) "
                "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?)",
                nombre, carga, 0, "archivada", "nueva", TOTAL, ahora, ahora).fetchone()[0]
            pagadas = 0
            for offset, k in perfil:
                for _ in range(k):
                    _cuenta(cur, asig_id, f"{seq:010d}", ahora, carga, carga + timedelta(days=offset))
                    seq += 1
                    pagadas += 1
            for _ in range(TOTAL - pagadas):
                _cuenta(cur, asig_id, f"{seq:010d}", ahora, carga, None)
                seq += 1
    print("Demo de meses sembrada (Diciembre, Enero, Febrero).")


def borrar():
    with db.cursor() as cur:
        cur.execute("DELETE FROM eventos_pago WHERE asignacion_cuenta_id IN "
                    "(SELECT c.id FROM asignacion_cuentas c JOIN asignaciones a "
                    "ON a.id=c.asignacion_id WHERE a.nombre LIKE 'Demo · %')")
        cur.execute("DELETE FROM asignacion_cuentas WHERE asignacion_id IN "
                    "(SELECT id FROM asignaciones WHERE nombre LIKE 'Demo · %')")
        cur.execute("DELETE FROM numeros WHERE numero LIKE '71%' AND numero >= '7100000000'")
        cur.execute("DELETE FROM asignaciones WHERE nombre LIKE 'Demo · %'")
    print("Demo de meses borrada.")


if __name__ == "__main__":
    import sys
    borrar() if "--borrar" in sys.argv else main()
