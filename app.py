#!/usr/bin/env python3
"""Volver a verde: finanzas del Airbnb familiar.

Servidor local sin dependencias (solo Python 3.9+). Guarda todo en data.db (SQLite).

    python3 app.py            # abre http://localhost:8000
    python3 app.py --demo     # carga datos de ejemplo (solo si la base está vacía)
"""
import csv
import io
import json
import os
import random
import sqlite3
import sys
import unicodedata
from datetime import date, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

BASE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.environ.get("VAV_DB", os.path.join(BASE, "data.db"))
STATIC = os.path.join(BASE, "static")
PORT = int(os.environ.get("PORT", "8000"))

CATEGORIAS = [
    "Limpieza", "Lavandería", "Luz", "Agua", "Gas", "Internet",
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
        if con.execute("SELECT COUNT(*) FROM cuartos").fetchone()[0] == 0:
            con.executemany("INSERT INTO cuartos (nombre) VALUES (?)",
                            [(f"Cuarto {i}",) for i in range(1, 6)])
        con.execute("INSERT OR IGNORE INTO config VALUES ('saldo_inicial', '0')")
        con.execute("INSERT OR IGNORE INTO config VALUES ('fecha_saldo', ?)",
                    (date.today().replace(day=1).isoformat(),))


def rows(con, sql, args=()):
    return [dict(r) for r in con.execute(sql, args).fetchall()]


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
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def body(self):
        n = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(n).decode("utf-8-sig") if n else ""
        if "json" in (self.headers.get("Content-Type") or ""):
            return json.loads(raw or "{}")
        return raw

    def do_GET(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        p = u.path
        if not p.startswith("/api/"):
            ruta = os.path.normpath(os.path.join(STATIC, (p.lstrip("/") or "index.html")))
            if not ruta.startswith(STATIC) or not os.path.isfile(ruta):
                ruta = os.path.join(STATIC, "index.html")
            with open(ruta, "rb") as fh:
                return self.send(200, fh.read(), TIPOS.get(os.path.splitext(ruta)[1], "application/octet-stream"))
        with db() as con:
            if p == "/api/inicio":
                return self.send(200, {"cuartos": rows(con, "SELECT * FROM cuartos ORDER BY id"),
                                       "categorias": CATEGORIAS, "config": get_config(con)})
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
                return self.send(200, rows(con, "SELECT * FROM gastos ORDER BY fecha DESC, id DESC LIMIT 500"))
            if p == "/api/movimientos":
                return self.send(200, rows(con, "SELECT * FROM movimientos ORDER BY fecha DESC"))
            if p.startswith("/api/exportar/"):
                que = p.rsplit("/", 1)[1]
                d, h = rango(q)
                nombre = f"{que}_{d}_{h}.csv"
                return self.send(200, exportar(con, que, d.isoformat(), h.isoformat()),
                                 "text/csv; charset=utf-8",
                                 {"Content-Disposition": f'attachment; filename="{nombre}"'})
        self.send(404, {"error": "no encontrado"})

    def do_POST(self):
        p = urlparse(self.path).path
        try:
            datos = self.body()
            with db() as con:
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
                if p == "/api/gastos":
                    campos = ("fecha", "monto", "categoria", "cuarto_id", "descripcion",
                              "personal", "factura", "de_la_cuenta")
                    vals = [datos.get(c) or None for c in campos[:5]] + [
                        1 if datos.get(c) in (True, 1, "1", "on") else 0 for c in campos[5:]]
                    if datos.get("id"):
                        con.execute(f"UPDATE gastos SET {', '.join(c + '=?' for c in campos)} WHERE id=?",
                                    vals + [datos["id"]])
                    else:
                        con.execute(f"INSERT INTO gastos ({', '.join(campos)}) VALUES ({','.join('?' * len(campos))})", vals)
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
        except Exception as e:  # noqa: BLE001
            return self.send(400, {"error": str(e)})
        self.send(404, {"error": "no encontrado"})

    def do_DELETE(self):
        partes = urlparse(self.path).path.strip("/").split("/")
        if len(partes) == 3 and partes[1] in ("reservas", "gastos", "movimientos"):
            with db() as con:
                con.execute(f"DELETE FROM {partes[1]} WHERE id = ?", (int(partes[2]),))
            return self.send(200, {"ok": True})
        self.send(404, {"error": "no encontrado"})


if __name__ == "__main__":
    init_db()
    if "--demo" in sys.argv:
        with db() as con:
            cargar_demo(con)
    print(f"Volver a verde corriendo en http://localhost:{PORT}  (Ctrl+C para salir)")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
