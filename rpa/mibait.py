"""Logica de scraping de mibait.com para el flujo 'Paga tu Plan'.

El formulario de pago NO esta en la pagina principal: vive en un iframe que
carga una app Angular separada (pospago.mibait.com/pagar-factura). Todo el
flujo —inputs, tarjetas de resultado y modales— ocurre dentro de ese frame.

Cuatro desenlaces posibles tras consultar un numero:
  - al_corriente : tarjeta 'Resumen de estado de Cuenta', saldo $0.00
  - con_saldo    : tarjeta 'Resumen de pago de Factura', monto + total
  - prepago      : modal #modalAlert
  - no_bait      : modal #errorsOtp
"""
from __future__ import annotations

import re
from dataclasses import dataclass

from playwright.sync_api import FrameLocator, Page, TimeoutError as PWTimeout

MIBAIT_URL = "https://mibait.com/"
PAGO_FRAME = 'iframe[src*="pospago"]'  # iframe del formulario de pago

_MONTO_RE = re.compile(r"\$\s*([\d,]+\.\d{2})")
_PERIODO_RE = re.compile(r"periodo\s+(\d{2}-\d{2}-\d{4})", re.IGNORECASE)
_LIMITE_RE = re.compile(r"antes del\s+(\d{2}-\d{2}-\d{4})", re.IGNORECASE)


@dataclass
class Resultado:
    numero: str
    desenlace: str  # al_corriente | con_saldo | prepago | no_bait | error
    saldo_pendiente: float | None = None
    monto_plan: float | None = None
    total: float | None = None
    periodo: str | None = None
    fecha_limite_pago: str | None = None
    raw_texto: str = ""
    error: str | None = None


def _todos_montos(texto: str) -> list[float]:
    return [float(x.replace(",", "")) for x in _MONTO_RE.findall(texto)]


def _aceptar_cookies(page: Page) -> None:
    for nombre in ("Aceptar", "Aceptar todo", "Aceptar todas", "Entendido"):
        try:
            btn = page.get_by_role("button", name=nombre)
            if btn.count() and btn.first.is_visible():
                btn.first.click(timeout=2000)
                return
        except PWTimeout:
            pass


def abrir_formulario(page: Page) -> FrameLocator:
    """Navega a la home, entra a 'Paga tu Plan' y devuelve el frame del
    formulario con los inputs ya visibles."""
    page.goto(MIBAIT_URL, wait_until="domcontentloaded", timeout=45000)
    page.wait_for_timeout(1500)
    _aceptar_cookies(page)
    page.get_by_role("button", name="Paga tu Plan").first.click(timeout=20000)
    frame = page.frame_locator(PAGO_FRAME)
    frame.locator('input[inputmode="numeric"]').first.wait_for(
        state="visible", timeout=25000
    )
    return frame


def _visible(loc) -> bool:
    try:
        return bool(loc.count()) and loc.first.is_visible()
    except PWTimeout:
        return False


def _llenar_verificado(campo, valor: str, intentos: int = 3) -> None:
    """Rellena un input de Angular y confirma que el valor quedo escrito;
    reintenta si el frame lo limpio durante una recarga."""
    for _ in range(intentos):
        campo.fill("")
        campo.fill(valor)
        if campo.input_value() == valor:
            return
    raise RuntimeError(f"no se pudo escribir '{valor}' en el campo")


def consultar(page: Page, numero: str, frame: FrameLocator | None = None) -> Resultado:
    """Llena el formulario del frame, envia y clasifica el desenlace.

    Los modales #modalAlert/#errorsOtp existen ocultos en el DOM desde el
    inicio, asi que no se puede esperar 'el primero que aparezca': se sondea
    explicitamente cual de los cuatro desenlaces queda visible."""
    if frame is None:
        frame = page.frame_locator(PAGO_FRAME)

    inputs = frame.locator('input[inputmode="numeric"]')
    inputs.first.wait_for(state="visible", timeout=25000)
    _llenar_verificado(inputs.nth(0), numero)
    _llenar_verificado(inputs.nth(1), numero)
    frame.locator('button[type="submit"]:has-text("Continuar")').first.click(
        timeout=15000
    )

    tarjeta = frame.locator("p.title-billing")
    prepago = frame.locator("dialog#modalAlert")
    no_bait = frame.locator("dialog#errorsOtp")

    # Sondear hasta 30s a que se resuelva alguno de los cuatro desenlaces.
    # Ojo: el propio formulario tiene un p.title-billing ('Llena los
    # siguientes campos'), por eso solo aceptamos la tarjeta cuando su
    # titulo es un 'Resumen ...' (estado de cuenta o pago de factura).
    desenlace = None
    for _ in range(60):
        if _visible(no_bait):
            desenlace = "no_bait"; break
        if _visible(prepago):
            desenlace = "prepago"; break
        if _visible(tarjeta) and "resumen" in tarjeta.first.inner_text().lower():
            desenlace = "tarjeta"; break
        page.wait_for_timeout(500)

    if desenlace is None:
        return Resultado(numero=numero, desenlace="error",
                         error="timeout esperando desenlace")
    if desenlace == "no_bait":
        return Resultado(numero=numero, desenlace="no_bait",
                         raw_texto=no_bait.first.inner_text().strip())
    if desenlace == "prepago":
        return Resultado(numero=numero, desenlace="prepago",
                         raw_texto=prepago.first.inner_text().strip())

    # Tarjeta de resumen: leer el contenedor card-body ancestro del titulo.
    contenedor = tarjeta.first.locator(
        "xpath=ancestor::div[contains(@class,'card-body')][1]"
    )
    raw = contenedor.inner_text().strip()
    titulo = tarjeta.first.inner_text().strip().lower()

    if "estado de cuenta" in titulo:
        montos = _todos_montos(raw)
        return Resultado(numero=numero, desenlace="al_corriente",
                         saldo_pendiente=montos[-1] if montos else None,
                         raw_texto=raw)

    montos = _todos_montos(raw)
    periodo = _PERIODO_RE.search(raw)
    limite = _LIMITE_RE.search(raw)
    return Resultado(
        numero=numero,
        desenlace="con_saldo",
        monto_plan=montos[0] if montos else None,
        total=montos[-1] if montos else None,
        periodo=periodo.group(1) if periodo else None,
        fecha_limite_pago=limite.group(1) if limite else None,
        raw_texto=raw,
    )
