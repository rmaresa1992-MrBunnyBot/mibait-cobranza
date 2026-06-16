# MiBait Cobranza — RPA + Dashboard

Sistema de cobranza para cuentas Bait. Tiene dos componentes que trabajan sobre
la **misma base SQL Server**:

- **`dashboard/`** — Aplicación Laravel 11. Carga carteras (Excel/CSV), gestiona
  asignaciones, y muestra indicadores, métricas evolutivas con proyección y el
  módulo de clientes recurrentes. Es el **dueño del esquema** (migraciones).
- **`rpa/`** — Bot Python + Playwright. Consulta cada número en mibait.com,
  clasifica el resultado, infiere los pagos comparando consultas sucesivas y
  escribe todo en la base. **No define estructura**, solo lee/escribe tablas que
  Laravel ya creó.

La idea central: mibait no expone la fecha de pago, así que se infiere tomando
"fotos" repetidas del saldo (tabla `consultas`) y comparándolas. El estatus de
cobranza (con_adeudo / pago_parcial / pago_total) es **derivado** del saldo.

## Esquema de datos (lo crean las migraciones)

`numeros` (catálogo de DN), `asignaciones` (carteras, una activa a la vez),
`asignacion_cuentas` (renglones: número + datos de cartera + estado), `consultas`
(snapshot por consulta del RPA), `eventos_pago` (pagos inferidos), `corridas_rpa`
(bitácora de ejecuciones), `configuracion` (clave-valor: concurrencia por franja).

## Requisitos del servidor

- **PHP 8.3+** con extensiones `sqlsrv` y `pdo_sqlsrv` (Microsoft Drivers for PHP).
- **Composer 2**.
- **Python 3.12+** (probado en 3.14).
- **SQL Server** 2019/2022 (Express sirve) y **ODBC Driver 17 o 18 for SQL Server**.
- No se requiere `ext-gd`: la lectura de Excel usa `openspout` (streaming).

## Despliegue del dashboard

```bash
cd dashboard
composer install
cp .env.example .env
# Editar .env: DB_HOST (instancia), DB_DATABASE, DB_USERNAME, DB_PASSWORD.
php artisan key:generate
php artisan migrate            # crea TODAS las tablas
php artisan serve              # dev; en producción usar IIS/Apache/Nginx + PHP-FPM
```

Notas de conexión SQL Server:
- Para una **instancia nombrada local sin SQL Browser**, deja `DB_PORT` vacío y
  pon el nombre de instancia en `DB_HOST` (p. ej. `SERVIDOR\SQLEXPRESS`); conecta
  por memoria compartida.
- `DB_ENCRYPT=no` y `DB_TRUST_SERVER_CERTIFICATE=true` evitan problemas de
  certificado en entornos locales; ajústalo según la política del servidor.
- La base debe existir antes de migrar (`CREATE DATABASE MiBait_Cobranza`).

## Despliegue del RPA

```bash
cd rpa
python -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt   # Windows
.venv\Scripts\python -m playwright install chromium
cp .env.example .env
# Editar .env con la misma conexión SQL que el dashboard.
```

Operación:
```bash
.venv\Scripts\python consultar_asignacion.py        # recorre la asignación activa
.venv\Scripts\python consultar_asignacion.py 50     # límite opcional de cuentas
.venv\Scripts\python check_setup.py                 # verifica conexión a la base
```

Para producción, agendar `consultar_asignacion.py` con el Programador de tareas
de Windows en la franja nocturna.

## Notas técnicas importantes (para quien despliegue)

- El formulario de pago de mibait vive en un **iframe** servido por
  `pospago.mibait.com/pagar-factura`. El RPA va **directo** a esa app, no a la
  home de mibait.com: cargar la home dispara un **403 anti-bot** (nginx) tras
  ~12 consultas; yendo directo se sostiene ~50 consultas/min sin bloqueo.
- El ritmo (pausas) y la franja nocturna son configurables en la tabla
  `configuracion` (editable desde la base) y respetados por el RPA, con backoff
  ante 403.
- Layout de cartera: el DN se toma de `NUMERO_TEL_CONTRATO` y el adeudo de
  `MONTO_EMISION`. Se conservan todas las emisiones (filas) por número. Descarga
  la plantilla de ejemplo desde el dashboard (pantalla *Cargar cartera*).

## Estructura

```
dashboard/   App Laravel (esquema, carga, dashboard)
rpa/         Bot Python + Playwright (consulta, inferencia)
```

> Credenciales y datos de cartera (`.env`, `*.xlsx`) están excluidos por
> `.gitignore` y no se versionan.
