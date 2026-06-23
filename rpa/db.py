"""Acceso a SQL Server para el RPA. El esquema lo define Laravel; aqui solo
leemos la asignacion activa y escribimos snapshots/eventos/corridas."""
import os
from contextlib import contextmanager

import pyodbc
from dotenv import load_dotenv

load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))

# Prefijo de esquema para calificar las tablas. Cuando la base real se accede
# por linked server (4 partes), DB_PREFIX vale, p. ej.:
#   [10.80.11.72\INTELIX].[Despliegue_Bait_Cobranza].[dbo]
# Si queda vacio se usan nombres simples (esquema dbo de la base conectada).
DB_PREFIX = os.environ.get("DB_PREFIX", "").strip()


def tabla(nombre: str) -> str:
    """Devuelve el nombre de tabla calificado con DB_PREFIX si esta definido."""
    return f"{DB_PREFIX}.[{nombre}]" if DB_PREFIX else nombre


def connection_string() -> str:
    return (
        f"DRIVER={{{os.environ['DB_DRIVER']}}};"
        f"SERVER={os.environ['DB_SERVER']};"
        f"DATABASE={os.environ['DB_DATABASE']};"
        f"UID={os.environ['DB_UID']};"
        f"PWD={os.environ['DB_PWD']};"
        "Encrypt=no;TrustServerCertificate=yes;"
    )


def connect() -> pyodbc.Connection:
    return pyodbc.connect(connection_string())


@contextmanager
def cursor():
    conn = connect()
    try:
        cur = conn.cursor()
        yield cur
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def get_config(clave: str, default: str | None = None) -> str | None:
    with cursor() as cur:
        row = cur.execute(
            f"SELECT valor FROM {tabla('configuracion')} WHERE clave = ?", clave
        ).fetchone()
        return row[0] if row else default
