"""Acceso a datos del RPA. El esquema lo define Laravel; aqui se lee la
asignacion activa y se escriben snapshots, eventos de pago y estado de
cuentas. Las funciones reciben un cursor pyodbc abierto."""
from __future__ import annotations

from datetime import datetime

from db import tabla


def _fecha(s: str | None):
    """'dd-mm-yyyy' -> date, o None."""
    if not s:
        return None
    d, m, y = s.split("-")
    return datetime(int(y), int(m), int(d)).date()


def asignacion_activa(cur):
    row = cur.execute(
        f"SELECT id, nombre FROM {tabla('asignaciones')} WHERE activa = 1"
    ).fetchone()
    return {"id": row[0], "nombre": row[1]} if row else None


def cuentas_due(cur, asignacion_id, corte):
    """Cuentas activas 'vencidas' para la ventana de refresco: nunca consultadas
    o con ultima consulta anterior a `corte` (= ahora - ventana). Se devuelven
    de la mas atrasada a la mas reciente para garantizar cobertura: el bot
    siempre persigue primero lo que lleva mas tiempo sin tocarse y nunca repite
    una cuenta ya consultada dentro de la ventana."""
    cur.execute(
        "SELECT id, numero, saldo_referencia, saldo_actual, estatus_cobranza, "
        f"ultima_consulta_at FROM {tabla('asignacion_cuentas')} "
        "WHERE asignacion_id = ? AND cerrada = 0 "
        "AND (ultima_consulta_at IS NULL OR ultima_consulta_at < ?) "
        "ORDER BY CASE WHEN ultima_consulta_at IS NULL THEN 0 ELSE 1 END, "
        "ultima_consulta_at ASC, id",
        asignacion_id, corte,
    )
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def crear_corrida(cur, asignacion_id, inicio, concurrencia):
    # OUTPUT no se permite contra una tabla remota (linked server, error 405),
    # asi que insertamos sin OUTPUT y recuperamos el id por separado. El RPA
    # crea una corrida por ejecucion y es secuencial (el panel impide doble
    # arranque), por lo que MAX(id) de la asignacion es la recien creada.
    corridas = tabla("corridas_rpa")
    cur.execute(
        f"INSERT INTO {corridas} (asignacion_id, inicio, estado, "
        "concurrencia, created_at, updated_at) VALUES (?,?,?,?,?,?)",
        asignacion_id, inicio, "en_curso", concurrencia, inicio, inicio,
    )
    row = cur.execute(
        f"SELECT MAX(id) FROM {corridas} WHERE asignacion_id = ?", asignacion_id
    ).fetchone()
    return row[0]


def cerrar_corrida(cur, corrida_id, fin, total, exitosas, errores, estado):
    cur.execute(
        f"UPDATE {tabla('corridas_rpa')} SET fin=?, total_consultadas=?, "
        "exitosas=?, errores=?, estado=?, updated_at=? WHERE id=?",
        fin, total, exitosas, errores, estado, fin, corrida_id,
    )


def insertar_consulta(cur, ac_id, ahora, r, saldo):
    cur.execute(
        f"INSERT INTO {tabla('consultas')} (asignacion_cuenta_id, fecha_consulta, "
        "desenlace, saldo_pendiente, monto_plan, total, periodo, "
        "fecha_limite_pago, raw_texto, created_at, updated_at) "
        "VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        ac_id, ahora, r.desenlace, saldo, r.monto_plan, r.total, r.periodo,
        _fecha(r.fecha_limite_pago), r.raw_texto, ahora, ahora,
    )


def insertar_evento(cur, ac_id, tipo, monto, antes, despues, ant_fecha, det):
    cur.execute(
        f"INSERT INTO {tabla('eventos_pago')} (asignacion_cuenta_id, tipo, "
        "monto_pagado, saldo_antes, saldo_despues, fecha_consulta_anterior, "
        "fecha_deteccion, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)",
        ac_id, tipo, monto, antes, despues, ant_fecha, det, det, det,
    )


def actualizar_cuenta(cur, ac_id, **campos):
    sets = ", ".join(f"{k} = ?" for k in campos)
    cur.execute(
        f"UPDATE {tabla('asignacion_cuentas')} SET {sets} WHERE id = ?",
        *campos.values(), ac_id,
    )
