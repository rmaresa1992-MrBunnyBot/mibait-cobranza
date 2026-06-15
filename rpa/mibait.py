"""Logica de scraping de mibait.com para el flujo 'Paga tu Plan'.

El formulario de pago es una app Angular servida en pospago.mibait.com. En el
sitio publico se embebe en un iframe, pero se puede abrir directamente, lo que
genera muchas menos peticiones y EVITA el 403 anti-bot que dispara cargar la
home completa de mibait.com (calibrado: 200+ consultas/seg sin bloqueo por la
via directa, vs ~12 recargando la home). Por eso vamos directo.

Cuatro desenlaces posibles tras consultar un numero:
  - al_corriente : tarjeta 'Resumen de estado de Cuenta', saldo $0.00
  - con_saldo    : tarjeta 'Resumen de pago de Factura', monto + total
  - prepago      : modal #modalAlert
  - no_bait      : modal #errorsOtp
"""
from __future__ import annotations

import re
from dataclasses import dataclass

from playwright.sync_api import Page, TimeoutError as PWTimeout

PAGO_URL = "https://pospago.mibait.com/pagar-factura"

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


def _visible(loc) -> bool:
    try:
        return bool(loc.count()) and loc.first.is_visible()
    except PWTimeout:
        return False


def _llenar_verificado(campo, valor: str, intentos: int = 3) -> None:
    """Rellena un input de Angular y confirma que el valor quedo escrito."""
    for _ in range(intentos):
        campo.fill("")
        campo.fill(valor)
        if campo.input_value() == valor:
            return
    raise RuntimeError(f"no se pudo escribir '{valor}' en el campo")


def es_403(page: Page) -> bool:
    try:
        return "403 Forbidden" in page.content()[:3000]
    except Exception:
        return False


def abrir_formulario(page: Page) -> None:
    """Navega directo a la app de pago y espera el formulario listo."""
    resp = page.goto(PAGO_URL, wait_until="domcontentloaded", timeout=45000)
    if (resp and resp.status == 403) or es_403(page):
        raise RuntimeError("403 Forbidden al abrir el formulario")
    page.locator('input[inputmode="numeric"]').first.wait_for(
        state="visible", timeout=25000
    )


def consultar(page: Page, numero: str) -> Resultado:
    """Asume el formulario abierto (abrir_formulario). Llena, envia y clasifica.

    El propio formulario tiene un p.title-billing ('Llena los siguientes
    campos') y los modales #modalAlert/#errorsOtp existen ocultos desde el
    inicio, asi que se sondea explicitamente el desenlace y solo se acepta la
    tarjeta cuando su titulo es un 'Resumen ...'."""
    inputs = page.locator('input[inputmode="numeric"]')
    inputs.first.wait_for(state="visible", timeout=25000)
    _llenar_verificado(inputs.nth(0), numero)
    _llenar_verificado(inputs.nth(1), numero)
    page.locator('button[type="submit"]:has-text("Continuar")').first.click(timeout=15000)

    tarjeta = page.locator("p.title-billing")
    prepago = page.locator("dialog#modalAlert")
    no_bait = page.locator("dialog#errorsOtp")

    desenlace = None
    for _ in range(60):
        if _visible(no_bait):
            desenlace = "no_bait"; break
        if _visible(prepago):
            desenlace = "prepago"; break
        if _visible(tarjeta) and "resumen" in tarjeta.first.inner_text().lower():
            desenlace = "tarjeta"; break
        if es_403(page):
            return Resultado(numero=numero, desenlace="error", error="403")
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
