"""Validacion del clasificador contra mibait.com con numeros de prueba.
Navegador visible, captura pantallas en capturas/ y vuelca el resultado."""
import os
import sys

from playwright.sync_api import sync_playwright

import mibait

NUMEROS = sys.argv[1:] or ["3330125202", "3314717449", "3338292348"]
CAP_DIR = os.path.join(os.path.dirname(__file__), "capturas")
os.makedirs(CAP_DIR, exist_ok=True)


def main() -> None:
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False, slow_mo=150)
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        page = ctx.new_page()
        for i, numero in enumerate(NUMEROS, 1):
            print(f"\n===== [{i}/{len(NUMEROS)}] Numero {numero} =====")
            try:
                frame = mibait.abrir_formulario(page)
                r = mibait.consultar(page, numero, frame)
                print(f"  desenlace      : {r.desenlace}")
                print(f"  saldo_pendiente: {r.saldo_pendiente}")
                print(f"  monto_plan     : {r.monto_plan}")
                print(f"  total          : {r.total}")
                print(f"  periodo        : {r.periodo}")
                print(f"  fecha_limite   : {r.fecha_limite_pago}")
                if r.error:
                    print(f"  error          : {r.error}")
                print(f"  raw_texto:\n    " + r.raw_texto.replace("\n", "\n    "))
            except Exception as e:  # noqa: BLE001 - exploracion, queremos ver todo
                print(f"  EXCEPCION: {type(e).__name__}: {e}")
            finally:
                page.screenshot(path=os.path.join(CAP_DIR, f"{i}_{numero}.png"))
        print(f"\nCapturas en: {CAP_DIR}")
        browser.close()


if __name__ == "__main__":
    main()
