"""Diagnostico de la secuencia tras dar Continuar: que aparece y cuando."""
import sys
from playwright.sync_api import sync_playwright
import mibait

NUM = sys.argv[1] if len(sys.argv) > 1 else "9611599581"


def estado(frame):
    def info(sel):
        loc = frame.locator(sel)
        try:
            n = loc.count()
            vis = loc.first.is_visible() if n else False
            txt = (loc.first.inner_text().strip()[:40].replace("\n", " ")) if vis else ""
            return f"n={n} vis={vis} {txt!r}"
        except Exception as e:
            return f"err {e}"
    return {
        "title-billing": info("p.title-billing"),
        "modalAlert": info("dialog#modalAlert"),
        "errorsOtp": info("dialog#errorsOtp"),
    }


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False, slow_mo=80)
        page = browser.new_context(viewport={"width": 1280, "height": 900}).new_page()
        frame = mibait.abrir_formulario(page)
        inputs = frame.locator('input[inputmode="numeric"]')
        inputs.nth(0).fill(NUM)
        inputs.nth(1).fill(NUM)
        frame.locator('button[type="submit"]:has-text("Continuar")').first.click()
        print(f"[enviado {NUM}] siguiendo estado cada 1.5s:")
        for i in range(18):
            page.wait_for_timeout(1500)
            st = estado(frame)
            print(f"  t={i*1.5:4.1f}s  TB[{st['title-billing']}]  "
                  f"ALERT[{st['modalAlert']}]  OTP[{st['errorsOtp']}]")
        browser.close()


if __name__ == "__main__":
    main()
