"""Calibracion del rate limit de mibait.

Mide cuantas consultas de factura tolera la IP antes del 403 y a que ritmo
se sostiene. Usa acceso directo a la app del iframe (pospago.mibait.com),
que es el flujo mas liviano. Detecta el 403 por status HTTP del goto y por
contenido. Headless para medir solo el limite de tasa del servidor.

Uso: calibrar.py [pausa_seg] [tope_consultas]
"""
import sys
import time

from playwright.sync_api import sync_playwright

PAGO_URL = "https://pospago.mibait.com/pagar-factura"
NUM = "3330125202"  # al corriente: respuesta rapida y estable

PAUSA = float(sys.argv[1]) if len(sys.argv) > 1 else 0.0
TOPE = int(sys.argv[2]) if len(sys.argv) > 2 else 40


def es_403(page) -> bool:
    try:
        return "403 Forbidden" in page.content()[:3000]
    except Exception:
        return False


def una_consulta(page) -> str:
    """Devuelve 'ok', '403' o 'err:<motivo>'."""
    resp = page.goto(PAGO_URL, wait_until="domcontentloaded", timeout=30000)
    status = resp.status if resp else None
    if status == 403 or es_403(page):
        return "403"
    inputs = page.locator('input[inputmode="numeric"]')
    try:
        inputs.first.wait_for(state="visible", timeout=15000)
    except Exception:
        return "403" if es_403(page) else "err:sin_form"
    inputs.nth(0).fill(NUM)
    inputs.nth(1).fill(NUM)
    page.locator('button[type="submit"]:has-text("Continuar")').first.click(timeout=10000)
    for _ in range(40):
        tb = page.locator("p.title-billing")
        try:
            if tb.count() and tb.first.is_visible() and "resumen" in tb.first.inner_text().lower():
                return "ok"
            if page.locator("dialog#modalAlert").first.is_visible():
                return "ok"
            if page.locator("dialog#errorsOtp").first.is_visible():
                return "ok"
        except Exception:
            pass
        if es_403(page):
            return "403"
        page.wait_for_timeout(250)
    return "err:timeout_desenlace"


def main():
    print(f"Calibracion: pausa={PAUSA}s entre consultas, tope={TOPE}, url directa")
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_context(
            viewport={"width": 1100, "height": 800},
            user_agent=("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                        "AppleWebKit/537.36 (KHTML, like Gecko) "
                        "Chrome/131.0.0.0 Safari/537.36"),
        ).new_page()
        t0 = time.time()
        exitos = 0
        for i in range(1, TOPE + 1):
            t = time.time() - t0
            try:
                r = una_consulta(page)
            except Exception as e:
                r = "403" if es_403(page) else f"exc:{type(e).__name__}"
            print(f"  #{i:2d}  t={t:6.1f}s  -> {r}", flush=True)
            if r == "403":
                print(f"\n>>> BLOQUEO 403 en la consulta #{i} (t={t:.1f}s, "
                      f"{exitos} exitosas previas)")
                medir_recuperacion(page, t0)
                break
            if r == "ok":
                exitos += 1
            if PAUSA:
                time.sleep(PAUSA)
        else:
            print(f"\n>>> SIN BLOQUEO en {TOPE} consultas. {exitos} exitosas, "
                  f"{time.time()-t0:.1f}s totales, "
                  f"{exitos/((time.time()-t0)/60):.1f} consultas/min")
        browser.close()


def medir_recuperacion(page, t0):
    """Tras un 403, sondea cada 30s cuanto tarda en liberarse (max 8 min)."""
    print(">>> Midiendo recuperacion (sondeo cada 30s, max 8 min)...")
    inicio = time.time()
    while time.time() - inicio < 480:
        time.sleep(30)
        try:
            resp = page.goto(PAGO_URL, wait_until="domcontentloaded", timeout=30000)
            libre = (resp and resp.status == 200) and not es_403(page)
        except Exception:
            libre = False
        esperado = time.time() - inicio
        print(f"    +{esperado:5.0f}s -> {'LIBRE' if libre else 'aun 403'}", flush=True)
        if libre:
            print(f">>> Recuperado tras ~{esperado:.0f}s")
            return
    print(">>> No se libero en 8 min")


if __name__ == "__main__":
    main()
