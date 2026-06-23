"""Servidor de control del RPA (solo stdlib). Sirve el panel HTML y expone una
API para arrancar/detener el bot, ver estatus en vivo y editar la configuracion.

El bot real sigue siendo consultar_asignacion.py: aqui se lanza como subproceso
y se detiene matando su arbol de procesos (incluye el Chromium de Playwright).

Uso: control_server.py [puerto]   (por defecto 8600)
"""
import json
import os
import subprocess
import sys
import threading
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import db

HERE = os.path.dirname(os.path.abspath(__file__))
HTML_PATH = os.path.join(HERE, "dashboard_control.html")
LOG_PATH = os.path.join(HERE, "bot.log")

# --- estado de los procesos del bot (protegido por lock) ---
_lock = threading.Lock()
_procs: list[subprocess.Popen] = []


def _vivos():
    return [p for p in _procs if p.poll() is None]


def _bot_running() -> bool:
    return len(_vivos()) > 0


def _en_franja_noche(now) -> bool:
    def hhmm(s):
        h, m = s.split(":")
        return int(h) * 60 + int(m)
    ini = hhmm(db.get_config("franja_noche_inicio", "23:00"))
    fin = hhmm(db.get_config("franja_noche_fin", "06:00"))
    actual = now.hour * 60 + now.minute
    if ini <= fin:
        return ini <= actual < fin
    return actual >= ini or actual < fin  # cruza medianoche


def _workers_config() -> int:
    """Workers segun la franja actual; lo que se configura en el panel."""
    clave = ("concurrencia_noche" if _en_franja_noche(datetime.now())
             else "concurrencia_dia")
    try:
        return max(1, int(db.get_config(clave, "1") or "1"))
    except ValueError:
        return 1


def start_bot(limite=None):
    global _procs
    with _lock:
        if _bot_running():
            return False, "El bot ya esta consultando."
        n = _workers_config()
        logf = open(LOG_PATH, "a", encoding="utf-8")
        logf.write(f"\n===== START {datetime.now():%Y-%m-%d %H:%M:%S} "
                   f"workers={n} limite={limite or 'todas'} =====\n")
        logf.flush()
        _procs = []
        for k in range(n):
            cmd = [sys.executable, "consultar_asignacion.py",
                   "--worker", str(k), "--workers", str(n)]
            if limite:
                cmd += ["--limite", str(int(limite))]
            _procs.append(subprocess.Popen(
                cmd, cwd=HERE, stdout=logf, stderr=subprocess.STDOUT,
                creationflags=subprocess.CREATE_NEW_PROCESS_GROUP,
            ))
        return True, f"Bot arrancado con {n} worker(s)."


def stop_bot():
    global _procs
    with _lock:
        vivos = _vivos()
        if not vivos:
            _procs = []
            return False, "El bot no esta corriendo."
        # /T mata todo el arbol de cada worker (incluye el Chromium de Playwright).
        for p in vivos:
            subprocess.run(["taskkill", "/PID", str(p.pid), "/T", "/F"],
                           capture_output=True)
        n = len(vivos)
        _procs = []
        return True, f"Bot detenido ({n} worker(s))."


# --- consultas de estatus (solo tablas autorizadas) ---

def leer_estatus():
    with db.cursor() as cur:
        row = cur.execute(
            f"SELECT TOP 1 id, nombre FROM {db.tabla('asignaciones')} "
            "WHERE activa = 1"
        ).fetchone()
        if not row:
            return {"running": _bot_running(), "asignacion": None}
        aid, nombre = row[0], row[1]
        ac = db.tabla("asignacion_cuentas")

        def cnt(sql, *p):
            return cur.execute(sql, *p).fetchone()[0]

        total = cnt(f"SELECT COUNT(*) FROM {ac} WHERE asignacion_id=? AND cerrada=0", aid)
        pendientes = cnt(f"SELECT COUNT(*) FROM {ac} WHERE asignacion_id=? AND "
                         "cerrada=0 AND ultima_consulta_at IS NULL", aid)
        consultadas = cnt(f"SELECT COUNT(*) FROM {ac} WHERE asignacion_id=? AND "
                          "cerrada=0 AND ultima_consulta_at IS NOT NULL", aid)
        cerradas = cnt(f"SELECT COUNT(*) FROM {ac} WHERE asignacion_id=? AND cerrada=1", aid)
        por_hora = cnt(
            f"SELECT COUNT(*) FROM {db.tabla('consultas')} "
            "WHERE fecha_consulta >= DATEADD(HOUR,-1,SYSDATETIME())")
        return {
            "running": _bot_running(),
            "workers": len(_vivos()),
            "asignacion": nombre,
            "total": total,
            "pendientes": pendientes,
            "consultadas": consultadas,
            "cerradas": cerradas,
            "por_hora": por_hora,
            "ts": f"{datetime.now():%H:%M:%S}",
        }


# --- claves de configuracion que el panel deja editar ---
CLAVES = [
    ("rpa_headless", "Navegador oculto (true/false)"),
    ("rpa_pausa_dia_seg", "Pausa entre consultas de dia (seg)"),
    ("rpa_pausa_noche_seg", "Pausa entre consultas de noche (seg)"),
    ("franja_noche_inicio", "Inicio franja noche (HH:MM)"),
    ("franja_noche_fin", "Fin franja noche (HH:MM)"),
    ("concurrencia_dia", "Workers de dia"),
    ("concurrencia_noche", "Workers de noche"),
]


def leer_config():
    with db.cursor() as cur:
        cur.execute(f"SELECT clave, valor FROM {db.tabla('configuracion')}")
        actual = {k: v for k, v in cur.fetchall()}
    return [{"clave": k, "desc": d, "valor": actual.get(k, "")} for k, d in CLAVES]


def guardar_config(items):
    permitidas = {k for k, _ in CLAVES}
    ahora = datetime.now()
    with db.cursor() as cur:
        cfg = db.tabla("configuracion")
        for it in items:
            clave, valor = it.get("clave"), str(it.get("valor", ""))
            if clave not in permitidas:
                continue
            n = cur.execute(f"UPDATE {cfg} SET valor=?, updated_at=? WHERE clave=?",
                            valor, ahora, clave).rowcount
            if n == 0:
                cur.execute(f"INSERT INTO {cfg} (clave, valor, created_at, updated_at) "
                            "VALUES (?,?,?,?)", clave, valor, ahora, ahora)


def leer_log(n=40):
    if not os.path.exists(LOG_PATH):
        return ""
    with open(LOG_PATH, encoding="utf-8", errors="replace") as f:
        return "".join(f.readlines()[-n:])


# --- HTTP ---

class Handler(BaseHTTPRequestHandler):
    def _json(self, obj, code=200):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _body(self):
        n = int(self.headers.get("Content-Length", 0) or 0)
        return json.loads(self.rfile.read(n) or b"{}") if n else {}

    def log_message(self, *a):  # silenciar log de acceso
        pass

    def do_GET(self):
        try:
            if self.path in ("/", "/index.html"):
                with open(HTML_PATH, "rb") as f:
                    data = f.read()
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            elif self.path == "/api/status":
                self._json(leer_estatus())
            elif self.path == "/api/config":
                self._json(leer_config())
            elif self.path == "/api/log":
                self._json({"log": leer_log()})
            else:
                self._json({"error": "no encontrado"}, 404)
        except Exception as e:  # noqa: BLE001
            self._json({"error": str(e)[:300]}, 500)

    def do_POST(self):
        try:
            if self.path == "/api/start":
                ok, msg = start_bot(self._body().get("limite"))
                self._json({"ok": ok, "msg": msg})
            elif self.path == "/api/stop":
                ok, msg = stop_bot()
                self._json({"ok": ok, "msg": msg})
            elif self.path == "/api/config":
                guardar_config(self._body().get("items", []))
                self._json({"ok": True, "msg": "Configuracion guardada."})
            else:
                self._json({"error": "no encontrado"}, 404)
        except Exception as e:  # noqa: BLE001
            self._json({"error": str(e)[:300]}, 500)


def main():
    puerto = int(sys.argv[1]) if len(sys.argv) > 1 else 8600
    srv = ThreadingHTTPServer(("127.0.0.1", puerto), Handler)
    print(f"Panel de control en http://127.0.0.1:{puerto}")
    print("Ctrl+C para detener el servidor (esto NO detiene el bot).")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        print("\nServidor detenido.")


if __name__ == "__main__":
    main()
