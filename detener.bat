@echo off
chcp 65001 >nul
title MiBait Cobranza - Detener
cd /d "%~dp0"

echo ============================================
echo   MiBait Cobranza - Deteniendo todo
echo ============================================
echo.

REM Cierra las ventanas que abrio iniciar.bat (panel, bot, dashboard) y todo
REM su arbol de procesos: el bot Python y el Chromium de Playwright incluidos.
echo Cerrando panel de control, bot y dashboard...
taskkill /FI "WINDOWTITLE eq MiBait Control*"   /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq MiBait Dashboard*" /T /F >nul 2>&1

REM Barrido de seguridad: Chromium que Playwright pudo dejar abierto.
taskkill /IM chrome.exe /FI "WINDOWTITLE eq about:blank*" /F >nul 2>&1

echo.
echo Listo. Se detuvieron el panel, el bot y el dashboard.
echo Si el bot estaba a medias, sus cambios ya quedaron guardados en la base.
echo.
timeout /t 3 /nobreak >nul
exit /b 0
