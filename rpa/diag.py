"""Diagnostico: enumerar frames y localizar el formulario de pago."""
from playwright.sync_api import sync_playwright

JS = """() => Array.from(document.querySelectorAll('input')).map(el => ({
  id: el.id, inputmode: el.getAttribute('inputmode'),
  vis: !!(el.offsetParent !== null && el.getClientRects().length),
}))"""


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False, slow_mo=100)
        page = browser.new_context(viewport={"width": 1280, "height": 900}).new_page()
        page.goto("https://mibait.com/", wait_until="domcontentloaded", timeout=45000)
        page.wait_for_timeout(2500)
        try:
            page.get_by_role("button", name="Aceptar").first.click(timeout=3000)
        except Exception:
            pass
        page.get_by_role("button", name="Paga tu Plan").first.click(timeout=10000)
        page.wait_for_timeout(3500)

        print(f"Total frames: {len(page.frames)}")
        for i, fr in enumerate(page.frames):
            try:
                inputs = fr.evaluate(JS)
            except Exception as e:
                inputs = f"(no eval: {e})"
            print(f"\n[frame {i}] name={fr.name!r}")
            print(f"  url={fr.url}")
            print(f"  inputs={inputs}")
        page.screenshot(path="capturas/diag_frames.png")
        browser.close()


if __name__ == "__main__":
    main()
