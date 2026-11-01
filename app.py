#!/usr/bin/env python3
"""Volver a verde: finanzas del Airbnb familiar.

Servidor sin dependencias (solo Python 3.9+). Guarda los datos en data.db (SQLite)
y los comprobantes en la carpeta uploads/.

    python3 app.py            # abre http://localhost:8000
    python3 app.py --demo     # carga datos de ejemplo (solo si la base está vacía)

Variables de entorno: PORT, HOST (usa 0.0.0.0 en un servidor), VAV_DB, VAV_UPLOADS.
"""
import csv
import hashlib
import hmac
import io
import json
import os
import random
import secrets
import sqlite3
import sys
import unicodedata
from datetime import date, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from http.cookies import SimpleCookie
from urllib.parse import parse_qs, unquote, urlparse

BASE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.environ.get("VAV_DB", os.path.join(BASE, "data.db"))
STATIC = os.path.join(BASE, "static")
PORT = int(os.environ.get("PORT", "8000"))
HOST = os.environ.get("HOST", "127.0.0.1")
UPLOADS = os.environ.get("VAV_UPLOADS", os.path.join(os.path.dirname(DB_PATH), "uploads"))
MAX_ARCHIVO = 15 * 1024 * 1024  # 15 MB por comprobante
TIPOS_COMPROBANTE = {"image/jpeg": ".jpg", "image/png": ".png", "image/webp": ".webp",
                     "image/heic": ".heic", "image/heif": ".heif", "application/pdf": ".pdf",
                     "application/xml": ".xml", "text/xml": ".xml"}

CATEGORIAS = [
    "Limpieza", "Lavandería", "Luz", "Agua", "Gas", "Internet", "Gasolina",
    "Mantenimiento", "Insumos", "Mobiliario y equipo", "Predial",
    "Contador", "Sueldos", "Otros",
]

SCHEMA = """
CREATE TABLE IF NOT EXISTS cuartos (
    id INTEGER PRIMARY KEY,
    nombre TEXT NOT NULL,
    anuncio_airbnb TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS reservas (
    id INTEGER PRIMARY KEY,
    cuarto_id INTEGER REFERENCES cuartos(id),
    codigo TEXT,
    huesped TEXT DEFAULT '',
    check_in TEXT NOT NULL,
    noches INTEGER NOT NULL,
    bruto REAL NOT NULL DEFAULT 0,
    comision REAL NOT NULL DEFAULT 0,
    impuestos REAL NOT NULL DEFAULT 0,
    fecha_pago TEXT,
    notas TEXT DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS reservas_codigo ON reservas(codigo) WHERE codigo IS NOT NULL AND codigo <> '';
CREATE TABLE IF NOT EXISTS gastos (
    id INTEGER PRIMARY KEY,
    fecha TEXT NOT NULL,
    monto REAL NOT NULL,
    categoria TEXT NOT NULL,
    cuarto_id INTEGER REFERENCES cuartos(id),
    descripcion TEXT DEFAULT '',
    personal INTEGER NOT NULL DEFAULT 0,      -- 1 = no es del negocio: no cuenta en la rentabilidad
    factura INTEGER NOT NULL DEFAULT 0,       -- 1 = tiene factura (CFDI)
    de_la_cuenta INTEGER NOT NULL DEFAULT 1   -- 1 = se pagó desde la cuenta del negocio
);
CREATE TABLE IF NOT EXISTS movimientos (
    id INTEGER PRIMARY KEY,
    fecha TEXT NOT NULL,
    monto REAL NOT NULL,
    concepto TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY,
    nombre TEXT NOT NULL,
    usuario TEXT NOT NULL UNIQUE,
    hash TEXT NOT NULL,
    admin INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS sesiones (
    token TEXT PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    creada TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS comprobantes (
    id INTEGER PRIMARY KEY,
    gasto_id INTEGER NOT NULL REFERENCES gastos(id) ON DELETE CASCADE,
    archivo TEXT NOT NULL,
    nombre_original TEXT DEFAULT '',
    tipo TEXT DEFAULT '',
    subido_por INTEGER REFERENCES usuarios(id),
    fecha TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS config (
    clave TEXT PRIMARY KEY,
    valor TEXT
);
"""


# ---------------------------------------------------------------- base de datos

def db():
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    return con


def init_db():
    with db() as con:
        con.executescript(SCHEMA)
        # Migración para bases creadas antes de estas columnas.
        cols = {r["name"] for r in con.execute("PRAGMA table_info(gastos)")}
        for c, d in (("personal", 0), ("factura", 0), ("de_la_cuenta", 1)):
            if c not in cols:
                con.execute(f"ALTER TABLE gastos ADD COLUMN {c} INTEGER NOT NULL DEFAULT {d}")
        if "registrado_por" not in cols:
            con.execute("ALTER TABLE gastos ADD COLUMN registrado_por INTEGER REFERENCES usuarios(id)")
        if con.execute("SELECT COUNT(*) FROM cuartos").fetchone()[0] == 0:
            con.executemany("INSERT INTO cuartos (nombre) VALUES (?)",
                            [(f"Cuarto {i}",) for i in range(1, 6)])
        con.execute("INSERT OR IGNORE INTO config VALUES ('saldo_inicial', '0')")
        con.execute("INSERT OR IGNORE INTO config VALUES ('fecha_saldo', ?)",
                    (date.today().replace(day=1).isoformat(),))


def rows(con, sql, args=()):
    return [dict(r) for r in con.execute(sql, args).fetchall()]


# ---------------------------------------------------------------- usuarios

def hash_password(pw, sal=None):
    sal = sal or secrets.token_hex(16)
    h = hashlib.pbkdf2_hmac("sha256", pw.encode(), sal.encode(), 200_000).hex()
    return f"{sal}${h}"


def password_ok(pw, guardado):
    sal, _ = guardado.split("$", 1)
    return hmac.compare_digest(hash_password(pw, sal), guardado)


def crear_usuario(con, nombre, usuario, pw, admin=False):
    usuario = norm(usuario).replace(" ", "")
    if not nombre or not usuario or len(pw or "") < 6:
        raise ValueError("Falta nombre o usuario, o la contraseña tiene menos de 6 caracteres")
    con.execute("INSERT INTO usuarios (nombre, usuario, hash, admin) VALUES (?,?,?,?)",
                (nombre.strip(), usuario, hash_password(pw), int(bool(admin))))


def nueva_sesion(con, usuario_id):
    token = secrets.token_urlsafe(32)
    con.execute("INSERT INTO sesiones VALUES (?,?,?)", (token, usuario_id, datetime.now().isoformat()))
    return token


def get_config(con):
    return {r["clave"]: r["valor"] for r in con.execute("SELECT * FROM config")}


def neto(r):
    return round((r["bruto"] or 0) - (r["comision"] or 0) - (r["impuestos"] or 0), 2)


# ---------------------------------------------------------------- cálculos

def parse_d(s):
    return datetime.strptime(s[:10], "%Y-%m-%d").date()


def noches_en_rango(check_in, noches, desde, hasta):
    """Noches de una reserva que caen entre desde y hasta (inclusive)."""
    ini = parse_d(check_in)
    fin = ini + timedelta(days=noches)  # día de salida (no cuenta)
    a, b = max(ini, desde), min(fin, hasta + timedelta(days=1))
    return max(0, (b - a).days)


def resumen(con, desde, hasta):
    cuartos = rows(con, "SELECT * FROM cuartos ORDER BY id")
    n_cuartos = len(cuartos) or 1
    dias = (hasta - desde).days + 1
    reservas = rows(con, "SELECT * FROM reservas")
    gastos = rows(con, "SELECT * FROM gastos WHERE personal = 0 AND fecha BETWEEN ? AND ?",
                  (desde.isoformat(), hasta.isoformat()))
    personales = con.execute("SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE personal = 1 "
                             "AND fecha BETWEEN ? AND ?", (desde.isoformat(), hasta.isoformat())).fetchone()[0]

    por_cuarto = {c["id"]: {"id": c["id"], "nombre": c["nombre"], "noches": 0,
                            "bruto": 0.0, "neto": 0.0, "gastos_directos": 0.0,
                            "gastos_generales": 0.0, "reservas": 0} for c in cuartos}
    tot = {"bruto": 0.0, "comision": 0.0, "impuestos": 0.0, "neto": 0.0,
           "noches": 0, "reservas": 0}

    for r in reservas:
        n = noches_en_rango(r["check_in"], r["noches"], desde, hasta)
        if n == 0:
            continue
        f = n / r["noches"] if r["noches"] else 0  # parte proporcional del periodo
        tot["noches"] += n
        tot["reservas"] += 1
        for k in ("bruto", "comision", "impuestos"):
            tot[k] += (r[k] or 0) * f
        tot["neto"] += neto(r) * f
        pc = por_cuarto.get(r["cuarto_id"])
        if pc:
            pc["noches"] += n
            pc["reservas"] += 1
            pc["bruto"] += (r["bruto"] or 0) * f
            pc["neto"] += neto(r) * f

    total_gastos = 0.0
    generales = 0.0
    por_categoria = {}
    for g in gastos:
        total_gastos += g["monto"]
        por_categoria[g["categoria"]] = por_categoria.get(g["categoria"], 0) + g["monto"]
        if g["cuarto_id"] in por_cuarto:
            por_cuarto[g["cuarto_id"]]["gastos_directos"] += g["monto"]
        else:
            generales += g["monto"]

    disponibles = n_cuartos * dias
    for pc in por_cuarto.values():
        pc["gastos_generales"] = generales / n_cuartos
        pc["utilidad"] = pc["neto"] - pc["gastos_directos"] - pc["gastos_generales"]
        pc["ocupacion"] = pc["noches"] / dias if dias else 0
        pc["tarifa_promedio"] = pc["bruto"] / pc["noches"] if pc["noches"] else 0
        pc["margen"] = pc["utilidad"] / pc["neto"] if pc["neto"] else 0

    utilidad = tot["neto"] - total_gastos
    return {
        "desde": desde.isoformat(), "hasta": hasta.isoformat(), "dias": dias,
        "totales": {
            **{k: round(v, 2) for k, v in tot.items()},
            "gastos": round(total_gastos, 2),
            "gastos_personales": round(personales, 2),
            "utilidad": round(utilidad, 2),
            "margen": utilidad / tot["neto"] if tot["neto"] else 0,
            "ocupacion": tot["noches"] / disponibles if disponibles else 0,
            "tarifa_promedio": tot["bruto"] / tot["noches"] if tot["noches"] else 0,
            "costo_por_noche": total_gastos / tot["noches"] if tot["noches"] else 0,
            "utilidad_por_noche": utilidad / tot["noches"] if tot["noches"] else 0,
        },
        "por_cuarto": [{k: (round(v, 4) if isinstance(v, float) else v)
                        for k, v in pc.items()} for pc in por_cuarto.values()],
        "por_categoria": sorted(({"categoria": k, "monto": round(v, 2)}
                                 for k, v in por_categoria.items()),
                                key=lambda x: -x["monto"]),
    }


def flujo(con):
    """Entradas (depósitos de Airbnb y otros) y salidas (gastos) por mes, y saldo."""
    cfg = get_config(con)
    saldo_ini = float(cfg.get("saldo_inicial") or 0)
    fecha_saldo = cfg.get("fecha_saldo") or "1900-01-01"
    movs = []
    for r in rows(con, "SELECT r.*, c.nombre AS cuarto FROM reservas r "
                       "LEFT JOIN cuartos c ON c.id = r.cuarto_id"):
        f = r["fecha_pago"] or (parse_d(r["check_in"]) + timedelta(days=1)).isoformat()
        movs.append({"fecha": f, "monto": neto(r), "tipo": "entrada",
                     "concepto": f"Airbnb {r['codigo'] or ''} {r['cuarto'] or ''} {r['huesped'] or ''}".strip()})
    for g in rows(con, "SELECT * FROM gastos WHERE de_la_cuenta = 1"):
        etiqueta = " (no es del negocio)" if g["personal"] else ""
        movs.append({"fecha": g["fecha"], "monto": -g["monto"], "tipo": "salida",
                     "concepto": f"{g['categoria']}: {g['descripcion'] or ''}".strip(": ") + etiqueta})
    for m in rows(con, "SELECT * FROM movimientos"):
        movs.append({"fecha": m["fecha"], "monto": m["monto"],
                     "tipo": "entrada" if m["monto"] >= 0 else "salida",
                     "concepto": m["concepto"] or "Ajuste"})
    movs = [m for m in movs if m["fecha"] >= fecha_saldo]
    hoy = date.today().isoformat()
    movs.sort(key=lambda m: m["fecha"])

    saldo = saldo_ini
    meses = {}
    futuros = [m for m in movs if m["fecha"] > hoy]
    movs = [m for m in movs if m["fecha"] <= hoy]
    for m in movs:
        saldo += m["monto"]
        m["saldo"] = round(saldo, 2)
        k = m["fecha"][:7]
        mes = meses.setdefault(k, {"mes": k, "entradas": 0.0, "salidas": 0.0})
        mes["entradas" if m["monto"] >= 0 else "salidas"] += abs(m["monto"])
    actual = saldo
    s = saldo_ini
    lista = []
    for k in sorted(meses):
        mes = meses[k]
        s += mes["entradas"] - mes["salidas"]
        lista.append({**{x: round(y, 2) if isinstance(y, float) else y for x, y in mes.items()},
                      "saldo_final": round(s, 2)})
    return {"saldo_inicial": saldo_ini, "fecha_saldo": fecha_saldo,
            "saldo_actual": round(actual, 2),
            "por_cobrar": round(sum(m["monto"] for m in futuros if m["monto"] > 0), 2),
            "meses": lista, "movimientos": list(reversed(movs))[:200]}


# ---------------------------------------------------------------- importación

def norm(s):
    s = unicodedata.normalize("NFKD", str(s or "")).encode("ascii", "ignore").decode()
    return s.strip().lower()


def num(s):
    s = str(s or "").replace("$", "").replace(",", "").replace("MXN", "").strip()
    if s.startswith("(") and s.endswith(")"):
        s = "-" + s[1:-1]
    try:
        return float(s) if s else 0.0
    except ValueError:
        return 0.0


def fecha(s):
    s = str(s or "").strip()
    for fmt in ("%m/%d/%Y", "%d/%m/%Y", "%Y-%m-%d", "%d-%m-%Y", "%m/%d/%y", "%d/%m/%y"):
        try:
            return datetime.strptime(s[:10], fmt).date().isoformat()
        except ValueError:
            pass
    return None


def leer_csv(texto):
    texto = texto.lstrip("﻿")
    muestra = texto[:2000]
    delim = ";" if muestra.count(";") > muestra.count(",") else ","
    lector = csv.DictReader(io.StringIO(texto), delimiter=delim)
    return [{norm(k): (v or "").strip() for k, v in fila.items() if k} for fila in lector]


def col(fila, *nombres):
    for n in nombres:
        if n in fila and fila[n] != "":
            return fila[n]
    return ""


def cuarto_por_anuncio(con, anuncio):
    if not anuncio:
        return None
    for c in rows(con, "SELECT * FROM cuartos"):
        if c["anuncio_airbnb"] and norm(c["anuncio_airbnb"]) == norm(anuncio):
            return c["id"]
    for c in rows(con, "SELECT * FROM cuartos"):
        if norm(c["nombre"]) and norm(c["nombre"]) in norm(anuncio):
            return c["id"]
    # Asigna el anuncio nuevo al primer cuarto que todavía no tenga uno.
    libre = con.execute("SELECT id FROM cuartos WHERE anuncio_airbnb = '' ORDER BY id LIMIT 1").fetchone()
    if libre:
        con.execute("UPDATE cuartos SET anuncio_airbnb = ? WHERE id = ?", (anuncio, libre["id"]))
        return libre["id"]
    return None


def importar_airbnb(con, texto):
    """Importa el 'Historial de transacciones' de Airbnb (CSV en español o inglés)."""
    filas = leer_csv(texto)
    reservas = {}
    pagos = []
    for f in filas:
        tipo = norm(col(f, "tipo", "type"))
        codigo = col(f, "codigo de confirmacion", "confirmation code")
        if tipo in ("reservacion", "reservation", "reserva"):
            r = reservas.setdefault(codigo or f"sin-codigo-{len(reservas)}", {
                "codigo": codigo,
                "huesped": col(f, "huesped", "guest"),
                "anuncio": col(f, "anuncio", "listing"),
                "check_in": fecha(col(f, "fecha de inicio", "start date")),
                "noches": int(num(col(f, "noches", "nights")) or 1),
                "bruto": 0.0, "comision": 0.0, "impuestos": 0.0, "fecha_pago": None,
            })
            importe = num(col(f, "importe", "amount"))
            comision = num(col(f, "tarifa de servicio", "service fee"))
            bruto = num(col(f, "ganancias brutas", "gross earnings")) or importe + comision
            r["bruto"] += bruto
            r["comision"] += comision
            r["impuestos"] += max(0.0, bruto - comision - importe)
            r["fecha_pago"] = fecha(col(f, "fecha", "date")) or r["fecha_pago"]
        elif "reten" in tipo or "withholding" in tipo or tipo in ("impuesto", "tax"):
            # Retenciones de ISR/IVA que Airbnb muestra como renglón aparte.
            if codigo in reservas:
                reservas[codigo]["impuestos"] += abs(num(col(f, "importe", "amount")))
            else:
                pagos.append((codigo, abs(num(col(f, "importe", "amount")))))
    nuevas = actualizadas = 0
    for r in reservas.values():
        if not r["check_in"]:
            continue
        cuarto = cuarto_por_anuncio(con, r["anuncio"])
        existe = con.execute("SELECT id FROM reservas WHERE codigo = ?", (r["codigo"],)).fetchone() if r["codigo"] else None
        vals = (cuarto, r["huesped"], r["check_in"], r["noches"], round(r["bruto"], 2),
                round(r["comision"], 2), round(r["impuestos"], 2), r["fecha_pago"])
        if existe:
            con.execute("UPDATE reservas SET cuarto_id=?, huesped=?, check_in=?, noches=?, bruto=?, "
                        "comision=?, impuestos=?, fecha_pago=? WHERE id=?", vals + (existe["id"],))
            actualizadas += 1
        else:
            con.execute("INSERT INTO reservas (cuarto_id, huesped, check_in, noches, bruto, comision, "
                        "impuestos, fecha_pago, codigo) VALUES (?,?,?,?,?,?,?,?,?)",
                        vals + (r["codigo"] or None,))
            nuevas += 1
    for codigo, monto in pagos:
        con.execute("UPDATE reservas SET impuestos = impuestos + ? WHERE codigo = ?", (monto, codigo))
    return {"nuevas": nuevas, "actualizadas": actualizadas, "filas": len(filas)}


def importar_gastos(con, texto):
    """CSV con columnas: fecha, monto, categoria y opcionales descripcion, cuarto, factura,
    personal, de la cuenta (estas tres con "si"/"no")."""
    cuartos = {norm(c["nombre"]): c["id"] for c in rows(con, "SELECT * FROM cuartos")}
    cats = {norm(c): c for c in CATEGORIAS}
    n = errores = 0
    for f in leer_csv(texto):
        fch = fecha(col(f, "fecha", "date"))
        monto = abs(num(col(f, "monto", "importe", "total", "cantidad", "amount")))
        if not fch or not monto:
            errores += 1
            continue
        cat = col(f, "categoria", "tipo", "concepto")
        cat = cats.get(norm(cat), cat.strip().capitalize() or "Otros")
        cuarto = cuartos.get(norm(col(f, "cuarto", "habitacion")))
        si = lambda v: norm(v) in ("si", "s", "x", "1", "yes", "true")
        personal = si(col(f, "personal", "no es del negocio"))
        factura = si(col(f, "factura", "facturado", "cfdi"))
        cuenta = col(f, "de la cuenta", "cuenta del negocio")
        de_la_cuenta = si(cuenta) if cuenta else 1
        con.execute("INSERT INTO gastos (fecha, monto, categoria, cuarto_id, descripcion, personal, factura, "
                    "de_la_cuenta) VALUES (?,?,?,?,?,?,?,?)",
                    (fch, monto, cat, cuarto, col(f, "descripcion", "nota", "notas", "detalle"),
                     int(personal), int(factura), int(de_la_cuenta)))
        n += 1
    return {"importados": n, "omitidos": errores}


# ---------------------------------------------------------------- exportación

def exportar(con, que, desde, hasta):
    out = io.StringIO()
    w = csv.writer(out)
    if que == "reservas":
        w.writerow(["Código", "Cuarto", "Huésped", "Check-in", "Noches", "Ingreso bruto",
                    "Comisión Airbnb", "Impuestos retenidos", "Neto depositado", "Fecha de pago"])
        for r in rows(con, "SELECT r.*, c.nombre AS cuarto FROM reservas r LEFT JOIN cuartos c "
                           "ON c.id = r.cuarto_id WHERE check_in BETWEEN ? AND ? ORDER BY check_in",
                      (desde, hasta)):
            w.writerow([r["codigo"] or "", r["cuarto"] or "", r["huesped"], r["check_in"], r["noches"],
                        f"{r['bruto']:.2f}", f"{r['comision']:.2f}", f"{r['impuestos']:.2f}",
                        f"{neto(r):.2f}", r["fecha_pago"] or ""])
    else:
        w.writerow(["Fecha", "Categoría", "Cuarto", "Descripción", "Monto", "Tiene factura",
                    "Del negocio", "Pagado desde la cuenta del negocio"])
        for g in rows(con, "SELECT g.*, c.nombre AS cuarto FROM gastos g LEFT JOIN cuartos c "
                           "ON c.id = g.cuarto_id WHERE fecha BETWEEN ? AND ? ORDER BY fecha",
                      (desde, hasta)):
            w.writerow([g["fecha"], g["categoria"], "" if g["personal"] else (g["cuarto"] or "General"), g["descripcion"],
                        f"{g['monto']:.2f}", "Sí" if g["factura"] else "No",
                        "No" if g["personal"] else "Sí", "Sí" if g["de_la_cuenta"] else "No"])
    return "﻿" + out.getvalue()  # BOM para que Excel lea bien los acentos


# ---------------------------------------------------------------- datos de ejemplo

def cargar_demo(con):
    if con.execute("SELECT COUNT(*) FROM reservas").fetchone()[0]:
        print("La base ya tiene reservas; no se cargan datos de ejemplo.")
        return
    random.seed(7)
    hoy = date.today()
    inicio = (hoy.replace(day=1) - timedelta(days=150)).replace(day=1)
    tarifas = {1: 650, 2: 700, 3: 800, 4: 550, 5: 900}
    demanda = {1: 0.75, 2: 0.65, 3: 0.8, 4: 0.4, 5: 0.6}
    k = 0
    for cid, tarifa in tarifas.items():
        d = inicio
        while d < hoy + timedelta(days=20):
            if random.random() < demanda[cid]:
                n = random.choice([1, 2, 2, 3, 4, 5])
                bruto = tarifa * n + 250
                comision = round(bruto * 0.03, 2)
                imp = round((bruto - comision) * 0.04 + bruto * 0.08, 2)
                k += 1
                con.execute("INSERT INTO reservas (cuarto_id, codigo, huesped, check_in, noches, bruto, "
                            "comision, impuestos, fecha_pago) VALUES (?,?,?,?,?,?,?,?,?)",
                            (cid, f"HM{k:06d}", random.choice(["Ana", "Luis", "Marta", "John", "Sofía", "Pedro"]),
                             d.isoformat(), n, bruto, comision, imp, (d + timedelta(days=1)).isoformat()))
                d += timedelta(days=n)
            else:
                d += timedelta(days=random.choice([1, 2, 3]))
    m = inicio
    while m <= hoy:
        def g(dia, monto, cat, desc, cuarto=None):
            if m.replace(day=dia) > hoy:
                return
            con.execute("INSERT INTO gastos (fecha, monto, categoria, cuarto_id, descripcion) VALUES (?,?,?,?,?)",
                        (m.replace(day=dia).isoformat(), monto, cat, cuarto, desc))
        g(5, 1400 + random.randint(0, 600), "Luz", "CFE")
        g(8, 450, "Agua", "Recibo de agua")
        g(10, 389, "Internet", "Internet fibra")
        g(12, 700, "Gas", "Tanque de gas")
        g(15, 9000, "Limpieza", "Sueldo de limpieza")
        g(28, 8000, "Sueldos", "Encargado / recepción")
        g(20, 900 + random.randint(0, 500), "Insumos", "Papel, jabón, café")
        g(25, 1200, "Lavandería", "Sábanas y toallas")
        if random.random() < 0.5:
            g(18, random.choice([800, 1500, 2500]), "Mantenimiento", "Reparación", random.randint(1, 5))
        m = (m + timedelta(days=32)).replace(day=1)
    con.execute("UPDATE config SET valor = ? WHERE clave = 'saldo_inicial'", ("15000",))
    con.execute("UPDATE config SET valor = ? WHERE clave = 'fecha_saldo'", (inicio.isoformat(),))
    print(f"Datos de ejemplo cargados: {k} reservas desde {inicio}.")


# ---------------------------------------------------------------- servidor HTTP

TIPOS = {".html": "text/html; charset=utf-8", ".js": "text/javascript; charset=utf-8",
         ".css": "text/css; charset=utf-8", ".svg": "image/svg+xml"}


def rango(q):
    hoy = date.today()
    desde = fecha(q.get("desde", [""])[0]) or hoy.replace(day=1).isoformat()
    hasta = fecha(q.get("hasta", [""])[0]) or hoy.isoformat()
    return parse_d(desde), parse_d(hasta)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, code, body, tipo="application/json; charset=utf-8", extra=None):
        data = body if isinstance(body, bytes) else (
            body.encode() if isinstance(body, str) else json.dumps(body, ensure_ascii=False).encode())
        self.send_response(code)
        self.send_header("Content-Type", tipo)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("X-Content-Type-Options", "nosniff")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def raw(self):
        n = int(self.headers.get("Content-Length") or 0)
        if n > MAX_ARCHIVO:
            raise ValueError("El archivo pesa más de 15 MB")
        return self.rfile.read(n) if n else b""

    def body(self):
        raw = self.raw().decode("utf-8-sig")
        if "json" in (self.headers.get("Content-Type") or ""):
            return json.loads(raw or "{}")
        return raw

    # ---- sesión
    def usuario(self, con):
        c = SimpleCookie(self.headers.get("Cookie") or "")
        token = c["vav"].value if "vav" in c else ""
        if not token:
            return None
        r = con.execute("SELECT u.id, u.nombre, u.usuario, u.admin FROM sesiones s JOIN usuarios u "
                        "ON u.id = s.usuario_id WHERE s.token = ?", (token,)).fetchone()
        return dict(r) if r else None

    def cookie(self, token, borrar=False):
        segura = "; Secure" if self.headers.get("X-Forwarded-Proto") == "https" else ""
        edad = 0 if borrar else 60 * 60 * 24 * 90
        return {"Set-Cookie": f"vav={token}; Path=/; HttpOnly; SameSite=Lax; Max-Age={edad}{segura}"}

    def estatico(self, p):
        ruta = os.path.normpath(os.path.join(STATIC, (p.lstrip("/") or "index.html")))
        if not ruta.startswith(STATIC) or not os.path.isfile(ruta):
            ruta = os.path.join(STATIC, "index.html")
        with open(ruta, "rb") as fh:
            return self.send(200, fh.read(), TIPOS.get(os.path.splitext(ruta)[1], "application/octet-stream"))

    # ---- GET
    def do_GET(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        p = u.path
        if not p.startswith("/api/"):
            return self.estatico(p)
        with db() as con:
            yo = self.usuario(con)
            if p == "/api/estado":
                hay = con.execute("SELECT COUNT(*) FROM usuarios").fetchone()[0] > 0
                return self.send(200, {"hay_usuarios": hay, "usuario": yo})
            if not yo:
                return self.send(401, {"error": "Inicia sesión"})
            if p == "/api/inicio":
                return self.send(200, {"cuartos": rows(con, "SELECT * FROM cuartos ORDER BY id"),
                                       "categorias": CATEGORIAS, "config": get_config(con), "yo": yo})
            if p == "/api/resumen":
                return self.send(200, resumen(con, *rango(q)))
            if p == "/api/flujo":
                return self.send(200, flujo(con))
            if p == "/api/reservas":
                lista = rows(con, "SELECT * FROM reservas ORDER BY check_in DESC LIMIT 500")
                for r in lista:
                    r["neto"] = neto(r)
                return self.send(200, lista)
            if p == "/api/gastos":
                lista = rows(con, "SELECT g.*, u.nombre AS registrado_por_nombre FROM gastos g "
                                  "LEFT JOIN usuarios u ON u.id = g.registrado_por "
                                  "ORDER BY g.fecha DESC, g.id DESC LIMIT 500")
                comp = {}
                for c in rows(con, "SELECT id, gasto_id, nombre_original, tipo FROM comprobantes"):
                    comp.setdefault(c["gasto_id"], []).append(c)
                for g in lista:
                    g["comprobantes"] = comp.get(g["id"], [])
                return self.send(200, lista)
            if p.startswith("/api/comprobantes/"):
                c = con.execute("SELECT * FROM comprobantes WHERE id = ?",
                                (int(p.rsplit("/", 1)[1]),)).fetchone()
                ruta = os.path.join(UPLOADS, c["archivo"]) if c else ""
                if not c or not os.path.isfile(ruta):
                    return self.send(404, {"error": "No se encontró el comprobante"})
                with open(ruta, "rb") as fh:
                    nombre = c["nombre_original"].replace('"', "")
                    return self.send(200, fh.read(), c["tipo"] or "application/octet-stream",
                                     {"Content-Disposition": f'inline; filename="{nombre}"'})
            if p == "/api/movimientos":
                return self.send(200, rows(con, "SELECT * FROM movimientos ORDER BY fecha DESC"))
            if p == "/api/usuarios":
                return self.send(200, rows(con, "SELECT id, nombre, usuario, admin FROM usuarios ORDER BY id"))
            if p.startswith("/api/exportar/"):
                que = p.rsplit("/", 1)[1]
                d, h = rango(q)
                nombre = f"{que}_{d}_{h}.csv"
                return self.send(200, exportar(con, que, d.isoformat(), h.isoformat()),
                                 "text/csv; charset=utf-8",
                                 {"Content-Disposition": f'attachment; filename="{nombre}"'})
        self.send(404, {"error": "no encontrado"})

    # ---- POST
    def do_POST(self):
        p = urlparse(self.path).path
        try:
            with db() as con:
                yo = self.usuario(con)
                # Subir comprobante: el cuerpo es el archivo tal cual.
                if p.startswith("/api/gastos/") and p.endswith("/comprobante"):
                    if not yo:
                        return self.send(401, {"error": "Inicia sesión"})
                    gasto_id = int(p.split("/")[3])
                    if not con.execute("SELECT 1 FROM gastos WHERE id = ?", (gasto_id,)).fetchone():
                        return self.send(404, {"error": "No existe el gasto"})
                    tipo = (self.headers.get("Content-Type") or "").split(";")[0].strip().lower()
                    if tipo not in TIPOS_COMPROBANTE:
                        return self.send(400, {"error": "Solo fotos (JPG, PNG, HEIC), PDF o XML"})
                    datos = self.raw()
                    if not datos:
                        return self.send(400, {"error": "Archivo vacío"})
                    os.makedirs(UPLOADS, exist_ok=True)
                    archivo = f"{gasto_id}_{secrets.token_hex(8)}{TIPOS_COMPROBANTE[tipo]}"
                    with open(os.path.join(UPLOADS, archivo), "wb") as fh:
                        fh.write(datos)
                    con.execute("INSERT INTO comprobantes (gasto_id, archivo, nombre_original, tipo, "
                                "subido_por, fecha) VALUES (?,?,?,?,?,?)",
                                (gasto_id, archivo, unquote(self.headers.get("X-Nombre") or archivo)[:200],
                                 tipo, yo["id"], datetime.now().isoformat()))
                    return self.send(200, {"ok": True})

                datos = self.body()
                if p == "/api/login":
                    r = con.execute("SELECT * FROM usuarios WHERE usuario = ?",
                                    (norm(datos.get("usuario", "")).replace(" ", ""),)).fetchone()
                    if not r or not password_ok(datos.get("password", ""), r["hash"]):
                        return self.send(401, {"error": "Usuario o contraseña incorrectos"})
                    return self.send(200, {"ok": True}, extra=self.cookie(nueva_sesion(con, r["id"])))
                if p == "/api/primer-usuario":
                    if con.execute("SELECT COUNT(*) FROM usuarios").fetchone()[0]:
                        return self.send(403, {"error": "Ya hay usuarios"})
                    crear_usuario(con, datos.get("nombre"), datos.get("usuario"), datos.get("password"), True)
                    uid = con.execute("SELECT id FROM usuarios").fetchone()[0]
                    con.execute("UPDATE gastos SET registrado_por = ? WHERE registrado_por IS NULL", (uid,))
                    return self.send(200, {"ok": True}, extra=self.cookie(nueva_sesion(con, uid)))
                if not yo:
                    return self.send(401, {"error": "Inicia sesión"})
                if p == "/api/logout":
                    c = SimpleCookie(self.headers.get("Cookie") or "")
                    if "vav" in c:
                        con.execute("DELETE FROM sesiones WHERE token = ?", (c["vav"].value,))
                    return self.send(200, {"ok": True}, extra=self.cookie("", borrar=True))
                if p == "/api/password":
                    actual = con.execute("SELECT hash FROM usuarios WHERE id = ?", (yo["id"],)).fetchone()[0]
                    if not password_ok(datos.get("actual", ""), actual):
                        return self.send(400, {"error": "La contraseña actual no es correcta"})
                    if len(datos.get("nueva") or "") < 6:
                        return self.send(400, {"error": "La nueva contraseña necesita 6 caracteres o más"})
                    con.execute("UPDATE usuarios SET hash = ? WHERE id = ?", (hash_password(datos["nueva"]), yo["id"]))
                    return self.send(200, {"ok": True})
                if p == "/api/gastos":
                    campos = ("fecha", "monto", "categoria", "cuarto_id", "descripcion",
                              "personal", "factura", "de_la_cuenta")
                    vals = [datos.get(c) or None for c in campos[:5]] + [
                        1 if datos.get(c) in (True, 1, "1", "on") else 0 for c in campos[5:]]
                    if datos.get("id"):
                        con.execute(f"UPDATE gastos SET {', '.join(c + '=?' for c in campos)} WHERE id=?",
                                    vals + [datos["id"]])
                        return self.send(200, {"ok": True, "id": int(datos["id"])})
                    cur = con.execute(f"INSERT INTO gastos ({', '.join(campos)}, registrado_por) "
                                      f"VALUES ({','.join('?' * (len(campos) + 1))})", vals + [yo["id"]])
                    return self.send(200, {"ok": True, "id": cur.lastrowid})

                # Lo demás solo lo hace un administrador.
                if not yo["admin"]:
                    return self.send(403, {"error": "Solo un administrador puede hacer esto"})
                if p == "/api/usuarios":
                    crear_usuario(con, datos.get("nombre"), datos.get("usuario"), datos.get("password"),
                                  datos.get("admin") in (True, 1, "1", "on"))
                    return self.send(200, {"ok": True})
                if p == "/api/reservas":
                    campos = ("cuarto_id", "codigo", "huesped", "check_in", "noches", "bruto",
                              "comision", "impuestos", "fecha_pago", "notas")
                    vals = [datos.get(c) or None for c in campos]
                    if datos.get("id"):
                        con.execute(f"UPDATE reservas SET {', '.join(c + '=?' for c in campos)} WHERE id=?",
                                    vals + [datos["id"]])
                    else:
                        con.execute(f"INSERT INTO reservas ({', '.join(campos)}) VALUES ({','.join('?' * len(campos))})", vals)
                    return self.send(200, {"ok": True})
                if p == "/api/movimientos":
                    con.execute("INSERT INTO movimientos (fecha, monto, concepto) VALUES (?,?,?)",
                                (datos["fecha"], float(datos["monto"]), datos.get("concepto", "")))
                    return self.send(200, {"ok": True})
                if p == "/api/cuartos":
                    for c in datos:
                        con.execute("UPDATE cuartos SET nombre=?, anuncio_airbnb=? WHERE id=?",
                                    (c["nombre"], c.get("anuncio_airbnb", ""), c["id"]))
                    return self.send(200, {"ok": True})
                if p == "/api/config":
                    for k in ("saldo_inicial", "fecha_saldo"):
                        if k in datos:
                            con.execute("INSERT OR REPLACE INTO config VALUES (?, ?)", (k, str(datos[k])))
                    return self.send(200, {"ok": True})
                if p == "/api/importar/airbnb":
                    return self.send(200, importar_airbnb(con, datos))
                if p == "/api/importar/gastos":
                    return self.send(200, importar_gastos(con, datos))
        except sqlite3.IntegrityError:
            return self.send(400, {"error": "Ese nombre de usuario ya existe"})
        except Exception as e:  # noqa: BLE001
            return self.send(400, {"error": str(e)})
        self.send(404, {"error": "no encontrado"})

    # ---- DELETE
    def do_DELETE(self):
        partes = urlparse(self.path).path.strip("/").split("/")
        if len(partes) != 3 or partes[1] not in ("reservas", "gastos", "movimientos", "comprobantes", "usuarios"):
            return self.send(404, {"error": "no encontrado"})
        tabla, rid = partes[1], int(partes[2])
        with db() as con:
            yo = self.usuario(con)
            if not yo:
                return self.send(401, {"error": "Inicia sesión"})
            # Un socio puede borrar sus propios gastos y comprobantes; lo demás, solo un administrador.
            if not yo["admin"]:
                if tabla == "gastos":
                    dueno = con.execute("SELECT registrado_por FROM gastos WHERE id = ?", (rid,)).fetchone()
                elif tabla == "comprobantes":
                    dueno = con.execute("SELECT subido_por FROM comprobantes WHERE id = ?", (rid,)).fetchone()
                else:
                    dueno = None
                if not dueno or dueno[0] != yo["id"]:
                    return self.send(403, {"error": "Solo puedes borrar lo que tú registraste"})
            if tabla == "usuarios" and rid == yo["id"]:
                return self.send(400, {"error": "No puedes borrar tu propio usuario"})
            if tabla in ("gastos", "comprobantes"):
                col_id = "gasto_id" if tabla == "gastos" else "id"
                for c in con.execute(f"SELECT archivo FROM comprobantes WHERE {col_id} = ?", (rid,)).fetchall():
                    try:
                        os.remove(os.path.join(UPLOADS, c[0]))
                    except OSError:
                        pass
            con.execute(f"DELETE FROM {tabla} WHERE id = ?", (rid,))
        self.send(200, {"ok": True})


if __name__ == "__main__":
    init_db()
    if "--demo" in sys.argv:
        with db() as con:
            cargar_demo(con)
    os.makedirs(UPLOADS, exist_ok=True)
    print(f"Volver a verde corriendo en http://localhost:{PORT}  (Ctrl+C para salir)")
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
