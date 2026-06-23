@echo off
chcp 65001 >nul
title MiBait Cobranza - Lanzador
cd /d "%~dp0"

echo ============================================
echo   MiBait Cobranza - Iniciando componentes
echo ============================================
echo.

REM --- Verificaciones rapidas ---
if not exist "dashboard\artisan" (
    echo [ERROR] No se encuentra dashboard\artisan. Ejecuta este .bat desde la raiz del proyecto.
    pause
    exit /b 1
)
if not exist "rpa\.venv\Scripts\python.exe" (
    echo [ERROR] No existe rpa\.venv. Crea el entorno: cd rpa ^&^& python -m venv .venv ^&^& .venv\Scripts\python -m pip install -r requirements.txt
    pause
    exit /b 1
)

REM --- 1) Dashboard Laravel (puerto 8000) ---
echo [1/3] Lanzando dashboard (Laravel) en http://127.0.0.1:8000 ...
start "MiBait Dashboard" cmd /k "cd /d "%~dp0dashboard" && php artisan serve"

REM --- 2) Bot de consulta RPA ---
echo [2/3] Lanzando bot de consulta (RPA) ...
start "MiBait Bot Consulta" cmd /k "cd /d "%~dp0rpa" && .venv\Scripts\python consultar_asignacion.py"

REM --- 3) Abrir el dashboard en el navegador ---
echo [3/3] Esperando a que el servidor levante y abriendo el navegador ...
timeout /t 4 /nobreak >nul
start "" "http://127.0.0.1:8000"

echo.
echo Listo. Se abrieron dos ventanas: "MiBait Dashboard" y "MiBait Bot Consulta".
echo Cierra esas ventanas para detener cada componente.
echo.
exit /b 0
