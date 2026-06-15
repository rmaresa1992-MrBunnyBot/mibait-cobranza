"""Acceso a SQL Server para el RPA. El esquema lo define Laravel; aqui solo
leemos la asignacion activa y escribimos snapshots/eventos/corridas."""
import os
from contextlib import contextmanager

import pyodbc
from dotenv import load_dotenv

load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))


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
            "SELECT valor FROM configuracion WHERE clave = ?", clave
        ).fetchone()
        return row[0] if row else default
