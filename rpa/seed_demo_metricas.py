"""Datos de DEMOSTRACION para lucir el tablero de metricas (curva,
comparativa, mejor dia, velocidad). Crea dos carteras con historia de pagos
escalonada. NO son datos reales; borrables con borrar_demo()."""
from datetime import date, datetime

import db

# (fecha_pago, monto) por cartera. Cada tupla = una cuenta pagada por completo.
JUNIO = [  # foco (activa), cargada 2026-05-25
    (date(2026, 5, 27), 199), (date(2026, 5, 29), 398), (date(2026, 6, 1), 298),
    (date(2026, 6, 3), 597), (date(2026, 6, 5), 199), (date(2026, 6, 8), 398),
    (date(2026, 6, 11), 298), (date(2026, 6, 14), 199),
]
MAYO = [  # anterior, cargada 2026-05-01
    (date(2026, 5, 2), 199), (date(2026, 5, 5), 298), (date(2026, 5, 9), 398),
    (date(2026, 5, 13), 199), (date(2026, 5, 18), 298),
]
ADEUDO_JUNIO = 4   # cuentas que siguen sin pagar (para que % < 100)
ADEUDO_MAYO = 6

# Numeros que aparecen en AMBAS carteras (para el panel de repetidas):
# en Mayo pagaron, en Junio una pago y otras siguen con adeudo.
REPETIDOS = ["5500000001", "5500000002", "5500000003"]


def _numero_id(cur, numero, ahora):
    row = cur.execute("SELECT id FROM numeros WHERE numero=?", numero).fetchone()
    if row:
        cur.execute("UPDATE numeros SET veces_asignado=veces_asignado+1, "
                    "updated_at=? WHERE id=?", ahora, row[0])
        return row[0]
    return cur.execute(
        "INSERT INTO numeros (numero, primera_vez_visto, veces_asignado, "
        "created_at, updated_at) OUTPUT INSERTED.id VALUES (?,?,?,?,?)",
        numero, ahora, 1, ahora, ahora).fetchone()[0]


def _cuenta(cur, asig_id, numero, ref, entrega, ahora, pago=None, monto_pagado=None):
    numero_id = _numero_id(cur, numero, ahora)
    estatus = "pago_total" if pago else "con_adeudo"
    cerrada = 1 if pago else 0
    saldo_act = 0 if pago else ref
    ac_id = cur.execute(
        "INSERT INTO asignacion_cuentas (asignacion_id, numero_id, numero, "
        "fecha_entrega, estatus_carga, saldo_referencia, saldo_actual, "
        "estatus_cobranza, tipo_linea, cerrada, fecha_pago_inferida, "
        "ultima_consulta_at, created_at, updated_at) "
        "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        asig_id, numero_id, numero, entrega, "con_adeudo", ref, saldo_act,
        estatus, "bait", cerrada, pago, ahora, ahora, ahora).fetchone()[0]
    if pago:
        det = datetime(pago.year, pago.month, pago.day, 3, 0, 0)
        cur.execute(
            "INSERT INTO eventos_pago (asignacion_cuenta_id, tipo, monto_pagado, "
            "saldo_antes, saldo_despues, fecha_deteccion, created_at, updated_at) "
            "VALUES (?,?,?,?,?,?,?,?)",
            ac_id, "pago_total", monto_pagado or ref, ref, 0, det, det, det)


def _cartera(cur, nombre, fecha_carga, activa, pagos, n_adeudo, base, ahora):
    asig_id = cur.execute(
        "INSERT INTO asignaciones (nombre, fecha_carga, activa, estado, "
        "tipo_origen, total_cuentas, created_at, updated_at) "
        "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?)",
        nombre, fecha_carga, activa, "activa" if activa else "archivada",
        "nueva", len(pagos) + n_adeudo, ahora, ahora).fetchone()[0]
    seq = base
    for fecha, monto in pagos:
        _cuenta(cur, asig_id, f"{seq:010d}", monto, fecha_carga, ahora,
                pago=fecha, monto_pagado=monto)
        seq += 1
    for _ in range(n_adeudo):
        _cuenta(cur, asig_id, f"{seq:010d}", 199, fecha_carga, ahora)
        seq += 1
    return asig_id


def borrar_demo():
    with db.cursor() as cur:
        cur.execute("DELETE FROM eventos_pago WHERE asignacion_cuenta_id IN "
                    "(SELECT c.id FROM asignacion_cuentas c JOIN asignaciones a "
                    "ON a.id=c.asignacion_id WHERE a.nombre LIKE 'Demo %')")
        cur.execute("DELETE FROM asignacion_cuentas WHERE asignacion_id IN "
                    "(SELECT id FROM asignaciones WHERE nombre LIKE 'Demo %')")
        cur.execute("DELETE FROM numeros WHERE numero LIKE '7%' AND numero >= '7000000000'")
        cur.execute("DELETE FROM numeros WHERE numero IN ('5500000001','5500000002','5500000003')")
        cur.execute("DELETE FROM asignaciones WHERE nombre LIKE 'Demo %'")
    print("Datos demo borrados.")


def main():
    ahora = datetime.now()
    borrar_demo()
    with db.cursor() as cur:
        cur.execute("UPDATE asignaciones SET activa=0, estado='archivada' WHERE activa=1")
        mayo = _cartera(cur, "Demo · Cartera Mayo", date(2026, 5, 1), 0, MAYO, ADEUDO_MAYO, 7000001000, ahora)
        junio = _cartera(cur, "Demo · Cartera Junio", date(2026, 5, 25), 1, JUNIO, ADEUDO_JUNIO, 7000002000, ahora)

        # Cuentas repetidas: en Mayo todas pagaron; en Junio solo una.
        for i, num in enumerate(REPETIDOS):
            _cuenta(cur, mayo, num, 199, date(2026, 5, 1), ahora,
                    pago=date(2026, 5, 6 + i * 3), monto_pagado=199)
        _cuenta(cur, junio, REPETIDOS[0], 199, date(2026, 5, 25), ahora,
                pago=date(2026, 6, 2), monto_pagado=199)
        _cuenta(cur, junio, REPETIDOS[1], 199, date(2026, 5, 25), ahora)
        _cuenta(cur, junio, REPETIDOS[2], 199, date(2026, 5, 25), ahora)
    print("Datos demo de metricas sembrados (2 carteras, con repetidas).")


if __name__ == "__main__":
    import sys
    borrar_demo() if "--borrar" in sys.argv else main()
