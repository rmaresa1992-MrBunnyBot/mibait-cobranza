"""Verificacion extremo a extremo de Fase 0: Python conecta a la misma base
que Laravel y lee la configuracion sembrada por la migracion."""
import db


def main() -> None:
    with db.cursor() as cur:
        version = cur.execute("SELECT @@VERSION").fetchone()[0].splitlines()[0]
        tablas = cur.execute(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'"
        ).fetchone()[0]
        print(f"Conectado a: {version}")
        print(f"Tablas en la base: {tablas}")
        print("Configuracion del RPA:")
        for clave, valor in cur.execute(
            "SELECT clave, valor FROM configuracion ORDER BY clave"
        ).fetchall():
            print(f"  {clave} = {valor}")


if __name__ == "__main__":
    main()
