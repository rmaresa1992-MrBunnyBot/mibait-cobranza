"""Siembra una asignacion de prueba con numeros que cubren los 4 desenlaces,
para validar el loop end-to-end sin esperar al dashboard. Archiva cualquier
asignacion activa previa (solo una activa a la vez)."""
from datetime import datetime

import db

NUMS = [
    "3330125202",  # al corriente
    "9611599581",  # con saldo (199)
    "9841257630",  # prepago
    "5582748332",  # no bait
    "3314717449",  # al corriente
    "3338292348",  # al corriente
]


def main():
    ahora = datetime.now()
    with db.cursor() as cur:
        cur.execute(
            "UPDATE asignaciones SET activa=0, estado='archivada', updated_at=? "
            "WHERE activa=1", ahora)
        asig_id = cur.execute(
            "INSERT INTO asignaciones (nombre, fecha_carga, activa, estado, "
            "tipo_origen, total_cuentas, created_at, updated_at) "
            "OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?)",
            "Prueba " + ahora.strftime("%Y-%m-%d %H:%M"), ahora.date(), 1,
            "activa", "nueva", len(NUMS), ahora, ahora).fetchone()[0]

        for num in NUMS:
            row = cur.execute("SELECT id FROM numeros WHERE numero=?", num).fetchone()
            if row:
                numero_id = row[0]
                cur.execute("UPDATE numeros SET veces_asignado=veces_asignado+1, "
                            "updated_at=? WHERE id=?", ahora, numero_id)
            else:
                numero_id = cur.execute(
                    "INSERT INTO numeros (numero, primera_vez_visto, "
                    "veces_asignado, created_at, updated_at) "
                    "OUTPUT INSERTED.id VALUES (?,?,?,?,?)",
                    num, ahora, 1, ahora, ahora).fetchone()[0]
            cur.execute(
                "INSERT INTO asignacion_cuentas (asignacion_id, numero_id, numero, "
                "fecha_entrega, estatus_carga, estatus_cobranza, tipo_linea, "
                "cerrada, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
                asig_id, numero_id, num, ahora.date(), "con_adeudo",
                "con_adeudo", "desconocido", 0, ahora, ahora)

    print(f"Asignacion de prueba id={asig_id} con {len(NUMS)} cuentas sembrada.")


if __name__ == "__main__":
    main()
