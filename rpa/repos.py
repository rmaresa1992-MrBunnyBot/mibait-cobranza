"""Acceso a datos del RPA. El esquema lo define Laravel; aqui se lee la
asignacion activa y se escriben snapshots, eventos de pago y estado de
cuentas. Las funciones reciben un cursor pyodbc abierto."""
from __future__ import annotations

from datetime import datetime


def _fecha(s: str | None):
    """'dd-mm-yyyy' -> date, o None."""
    if not s:
        return None
    d, m, y = s.split("-")
    return datetime(int(y), int(m), int(d)).date()


def asignacion_activa(cur):
    row = cur.execute(
        "SELECT id, nombre FROM asignaciones WHERE activa = 1"
    ).fetchone()
    return {"id": row[0], "nombre": row[1]} if row else None


def cuentas_pendientes(cur, asignacion_id):
    cur.execute(
        "SELECT id, numero, saldo_referencia, saldo_actual, estatus_cobranza, "
        "ultima_consulta_at FROM asignacion_cuentas "
        "WHERE asignacion_id = ? AND cerrada = 0 ORDER BY id",
        asignacion_id,
    )
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def crear_corrida(cur, asignacion_id, inicio, concurrencia):
    row = cur.execute(
        "INSERT INTO corridas_rpa (asignacion_id, inicio, estado, concurrencia, "
        "created_at, updated_at) OUTPUT INSERTED.id VALUES (?,?,?,?,?,?)",
        asignacion_id, inicio, "en_curso", concurrencia, inicio, inicio,
    ).fetchone()
    return row[0]


def cerrar_corrida(cur, corrida_id, fin, total, exitosas, errores, estado):
    cur.execute(
        "UPDATE corridas_rpa SET fin=?, total_consultadas=?, exitosas=?, "
        "errores=?, estado=?, updated_at=? WHERE id=?",
        fin, total, exitosas, errores, estado, fin, corrida_id,
    )


def insertar_consulta(cur, ac_id, ahora, r, saldo):
    cur.execute(
        "INSERT INTO consultas (asignacion_cuenta_id, fecha_consulta, desenlace, "
        "saldo_pendiente, monto_plan, total, periodo, fecha_limite_pago, "
        "raw_texto, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        ac_id, ahora, r.desenlace, saldo, r.monto_plan, r.total, r.periodo,
        _fecha(r.fecha_limite_pago), r.raw_texto, ahora, ahora,
    )


def insertar_evento(cur, ac_id, tipo, monto, antes, despues, ant_fecha, det):
    cur.execute(
        "INSERT INTO eventos_pago (asignacion_cuenta_id, tipo, monto_pagado, "
        "saldo_antes, saldo_despues, fecha_consulta_anterior, fecha_deteccion, "
        "created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)",
        ac_id, tipo, monto, antes, despues, ant_fecha, det, det, det,
    )


def actualizar_cuenta(cur, ac_id, **campos):
    sets = ", ".join(f"{k} = ?" for k in campos)
    cur.execute(
        f"UPDATE asignacion_cuentas SET {sets} WHERE id = ?",
        *campos.values(), ac_id,
    )
