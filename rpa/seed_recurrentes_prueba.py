"""Escenario TEMPORAL para validar el modulo de recurrentes: crea dos
carteras historicas que comparten numeros con la cartera activa, con distintos
patrones de pago (buen pagador / irregular / moroso). Borrable con --borrar."""
from datetime import date, datetime

import db


def crear_asig(cur, nombre, fecha_carga, ahora):
    return cur.execute(
        "INSERT INTO asignaciones (nombre, fecha_carga, activa, estado, "
        "tipo_origen, total_cuentas, created_at, updated_at) "
        "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?)",
        nombre, fecha_carga, 0, "archivada", "nueva", 0, ahora, ahora).fetchone()[0]


def cuenta_hist(cur, asig_id, numero, numero_id, ahora, pago_fecha):
    estatus = "pago_total" if pago_fecha else "con_adeudo"
    ac_id = cur.execute(
        "INSERT INTO asignacion_cuentas (asignacion_id, numero_id, numero, "
        "estatus_carga, saldo_referencia, saldo_actual, estatus_cobranza, "
        "tipo_linea, cerrada, fecha_pago_inferida, monto_emision, "
        "created_at, updated_at) OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        asig_id, numero_id, numero, "con_adeudo", 199, 0 if pago_fecha else 199,
        estatus, "bait", 1 if pago_fecha else 0, pago_fecha, 199, ahora, ahora
    ).fetchone()[0]
    if pago_fecha:
        det = datetime(pago_fecha.year, pago_fecha.month, pago_fecha.day, 3, 0, 0)
        cur.execute(
            "INSERT INTO eventos_pago (asignacion_cuenta_id, tipo, monto_pagado, "
            "saldo_antes, saldo_despues, fecha_deteccion, created_at, updated_at) "
            "VALUES (?,?,?,?,?,?,?,?)",
            ac_id, "pago_total", 199, 199, 0, det, det, det)


def main():
    ahora = datetime.now()
    with db.cursor() as cur:
        activa = cur.execute("SELECT id FROM asignaciones WHERE activa=1").fetchone()[0]
        nums = [r[0] for r in cur.execute(
            "SELECT DISTINCT TOP 8 numero FROM asignacion_cuentas "
            "WHERE asignacion_id=? ORDER BY numero", activa).fetchall()]
        ids = {n: cur.execute("SELECT id FROM numeros WHERE numero=?", n).fetchone()[0] for n in nums}

        ene = crear_asig(cur, "Histórico enero 2026", date(2026, 1, 15), ahora)
        mar = crear_asig(cur, "Histórico marzo 2026", date(2026, 3, 10), ahora)

        # 0-2: pagaron en ambas -> buen pagador; 3-4: solo enero -> irregular;
        # 5-7: no pagaron -> moroso.
        for i, n in enumerate(nums):
            pago_ene = date(2026, 1, 25) if i < 5 else None
            pago_mar = date(2026, 3, 20) if i < 3 else None
            cuenta_hist(cur, ene, n, ids[n], ahora, pago_ene)
            cuenta_hist(cur, mar, n, ids[n], ahora, pago_mar)
        for a in (ene, mar):
            cur.execute("UPDATE asignaciones SET total_cuentas=(SELECT COUNT(*) "
                        "FROM asignacion_cuentas WHERE asignacion_id=?) WHERE id=?", a, a)
    print(f"Escenario recurrentes creado con {len(nums)} numeros compartidos.")


def borrar():
    with db.cursor() as cur:
        cur.execute("DELETE FROM eventos_pago WHERE asignacion_cuenta_id IN "
                    "(SELECT c.id FROM asignacion_cuentas c JOIN asignaciones a "
                    "ON a.id=c.asignacion_id WHERE a.nombre LIKE 'Histórico %')")
        cur.execute("DELETE FROM asignacion_cuentas WHERE asignacion_id IN "
                    "(SELECT id FROM asignaciones WHERE nombre LIKE 'Histórico %')")
        cur.execute("DELETE FROM asignaciones WHERE nombre LIKE 'Histórico %'")
    print("Escenario recurrentes borrado.")


if __name__ == "__main__":
    import sys
    borrar() if "--borrar" in sys.argv else main()
