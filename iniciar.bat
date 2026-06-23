@echo off
chcp 65001 >nul
title MiBait Cobranza - Lanzador
cd /d "%~dp0"

echo ============================================
echo   MiBait Cobranza - Iniciando sistema
echo ============================================
echo.

REM --- Verificaciones rapidas ---
if not exist "rpa\.venv\Scripts\python.exe" (
    echo [ERROR] No existe rpa\.venv. Crea el entorno antes de continuar.
    pause
    exit /b 1
)
if not exist "rpa\control_server.py" (
    echo [ERROR] No se encuentra rpa\control_server.py
    pause
    exit /b 1
)

REM --- 1) Panel de control del bot (servidor Python, puerto 8600) ---
echo [1/3] Lanzando panel de control en http://127.0.0.1:8600 ...
start "MiBait Control" cmd /k "cd /d "%~dp0rpa" && .venv\Scripts\python control_server.py"

REM --- 2) Dashboard Laravel de metricas (puerto 8000), si existe ---
if exist "dashboard\artisan" (
    echo [2/3] Lanzando dashboard Laravel en http://127.0.0.1:8000 ...
    start "MiBait Dashboard" cmd /k "cd /d "%~dp0dashboard" && php artisan serve"
) else (
    echo [2/3] dashboard\artisan no encontrado, se omite el dashboard Laravel.
)

REM --- 3) Abrir el panel de control en el navegador ---
echo [3/3] Abriendo el panel de control ...
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:8600"

echo.
echo Sistema iniciado. Desde el panel (http://127.0.0.1:8600) arrancas y
echo detienes el bot, ves el estatus y editas la configuracion.
echo Para cerrar TODO el proceso, ejecuta:  detener.bat
echo.
exit /b 0
