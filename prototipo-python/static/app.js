// Volver a verde: interfaz (JavaScript sin dependencias)
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const mx = new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN", maximumFractionDigits: 0 });
const $m = (v) => mx.format(v || 0);
const pct = (v) => `${Math.round((v || 0) * 100)}%`;
const iso = (d) => d.toISOString().slice(0, 10);
const esc = (s) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
let INI = { cuartos: [], categorias: [], config: {} };

async function api(path, opts = {}) {
  const r = await fetch(path, opts);
  const j = await r.json();
  if (r.status === 401 && !path.startsWith("/api/login")) { mostrarLogin(false); throw new Error(j.error); }
  if (!r.ok) throw new Error(j.error || "Error");
  return j;
}
const post = (path, data) => api(path, {
  method: "POST",
  headers: { "Content-Type": typeof data === "string" ? "text/csv" : "application/json" },
  body: typeof data === "string" ? data : JSON.stringify(data),
});
function toast(msg) { const t = $("#toast"); t.textContent = msg; t.classList.add("on"); setTimeout(() => t.classList.remove("on"), 2200); }
const formData = (f) => Object.fromEntries(new FormData(f).entries());
const cuartoNombre = (id) => (INI.cuartos.find((c) => c.id == id) || {}).nombre || "General";

// ---------------- pestañas
$$("#tabs button").forEach((b) => b.onclick = () => {
  $$("#tabs button").forEach((x) => x.classList.toggle("activo", x === b));
  $$(".tab").forEach((t) => t.classList.toggle("activo", t.id === b.dataset.tab));
  cargar[b.dataset.tab]?.();
});

// ---------------- periodo
function rangoPeriodo() {
  const v = $("#periodo").value, h = new Date(), y = h.getFullYear(), m = h.getMonth();
  if (v === "mes") return [new Date(y, m, 1), h];
  if (v === "mes-1") return [new Date(y, m - 1, 1), new Date(y, m, 0)];
  if (v === "3m") return [new Date(y, m - 2, 1), h];
  if (v === "anio") return [new Date(y, 0, 1), h];
  if (v === "12m") return [new Date(y, m - 11, 1), h];
  return [new Date($("#desde").value || iso(new Date(y, m, 1))), new Date($("#hasta").value || iso(h))];
}
$("#periodo").onchange = () => { $(".filtros").classList.toggle("custom-on", $("#periodo").value === "custom"); cargar.resumen(); };
$("#desde").onchange = $("#hasta").onchange = () => cargar.resumen();

const kpi = (t, v, s = "", cls = "") => `<div class="kpi"><div class="t">${t}</div><div class="v ${cls}">${v}</div><div class="s">${s}</div></div>`;

// ---------------- conclusiones en lenguaje simple
function veredicto(r) {
  const t = r.totales, notas = [];
  if (!t.noches && !t.gastos) return { cls: "meh", html: "Todavía no hay datos en este periodo. Importa el CSV de Airbnb y tus gastos en <b>Importar / Contador</b>." };
  const cls = t.utilidad > 0 ? (t.margen >= 0.25 ? "bien" : "meh") : "mal";
  const titulo = t.utilidad > 0
    ? `<b>Sí hay ganancia:</b> quedaron ${$m(t.utilidad)} libres (${pct(t.margen)} de lo que depositó Airbnb).`
    : `<b>En este periodo se perdió dinero:</b> los gastos superaron lo depositado por ${$m(-t.utilidad)}.`;
  if (t.ocupacion < 0.5) notas.push(`La ocupación es de ${pct(t.ocupacion)}. Llenar más noches (precio, fotos, estancias mínimas) es la palanca más grande.`);
  const conNoches = r.por_cuarto.filter((c) => c.neto > 0);
  if (conNoches.length > 1) {
    const peor = [...r.por_cuarto].sort((a, b) => a.utilidad - b.utilidad)[0];
    const mejor = [...r.por_cuarto].sort((a, b) => b.utilidad - a.utilidad)[0];
    notas.push(`El cuarto que más deja es <b>${esc(mejor.nombre)}</b> (${$m(mejor.utilidad)}); el que menos, <b>${esc(peor.nombre)}</b> (${$m(peor.utilidad)}, ocupación ${pct(peor.ocupacion)}).`);
  }
  if (r.por_categoria.length && t.gastos) {
    const c = r.por_categoria[0];
    notas.push(`El gasto más grande es <b>${esc(c.categoria)}</b>: ${pct(c.monto / t.gastos)} de todos los gastos.`);
  }
  if (t.noches) notas.push(`Cada noche ocupada cuesta ${$m(t.costo_por_noche)} en gastos y deja ${$m(t.utilidad_por_noche)} de utilidad.`);
  return { cls, html: titulo + (notas.length ? `<ul>${notas.map((n) => `<li>${n}</li>`).join("")}</ul>` : "") };
}

// ---------------- cargadores por pestaña
const cargar = {
  async resumen() {
    const [d, h] = rangoPeriodo();
    const r = await api(`/api/resumen?desde=${iso(d)}&hasta=${iso(h)}`);
    const t = r.totales, v = veredicto(r);
    $("#veredicto").className = `veredicto ${v.cls}`;
    $("#veredicto").innerHTML = v.html;
    $("#kpis").innerHTML =
      kpi("Utilidad neta", $m(t.utilidad), `margen ${pct(t.margen)}`, t.utilidad >= 0 ? "pos" : "neg") +
      kpi("Depositado por Airbnb", $m(t.neto), `${t.reservas} reservas`) +
      kpi("Gastos del negocio", $m(t.gastos), t.gastos_personales ? `+ ${$m(t.gastos_personales)} que no son del negocio (no cuentan)` : "") +
      kpi("Ocupación", pct(t.ocupacion), `${t.noches} de ${r.dias * INI.cuartos.length} noches`) +
      kpi("Tarifa promedio", $m(t.tarifa_promedio), "por noche (bruto)");
    $("#cascada").innerHTML = `
      <tr><td>Lo que pagaron los huéspedes</td><td class="n">${$m(t.bruto)}</td></tr>
      <tr><td>− Comisión de Airbnb</td><td class="n neg">−${$m(t.comision)}</td></tr>
      <tr><td>− Impuestos retenidos por Airbnb</td><td class="n neg">−${$m(t.impuestos)}</td></tr>
      <tr class="total"><td>= Depositado</td><td class="n">${$m(t.neto)}</td></tr>
      <tr><td>− Gastos de operación</td><td class="n neg">−${$m(t.gastos)}</td></tr>
      <tr class="total"><td>= Utilidad neta</td><td class="n ${t.utilidad >= 0 ? "pos" : "neg"}">${$m(t.utilidad)}</td></tr>`;
    const max = Math.max(...r.por_categoria.map((c) => c.monto), 1);
    $("#categorias").innerHTML = r.por_categoria.map((c) =>
      `<div class="barra-cat"><span>${esc(c.categoria)}</span><div class="b" style="width:${(c.monto / max) * 100}%"></div><span class="m">${$m(c.monto)}</span></div>`
    ).join("") || '<p class="ayuda">Sin gastos en este periodo.</p>';
    $("#cuartosTabla").innerHTML = `<tr><th>Cuarto</th><th class="n">Noches</th><th class="n">Ocupación</th><th class="n">Tarifa prom.</th><th class="n">Depositado</th><th class="n">Gastos propios</th><th class="n">Gastos generales</th><th class="n">Utilidad</th><th class="n">Margen</th></tr>` +
      r.por_cuarto.map((c) => `<tr><td>${esc(c.nombre)}</td><td class="n">${c.noches}</td><td class="n">${pct(c.ocupacion)}</td><td class="n">${$m(c.tarifa_promedio)}</td><td class="n">${$m(c.neto)}</td><td class="n">${$m(c.gastos_directos)}</td><td class="n">${$m(c.gastos_generales)}</td><td class="n ${c.utilidad >= 0 ? "pos" : "neg"}">${$m(c.utilidad)}</td><td class="n">${pct(c.margen)}</td></tr>`).join("");
  },

  async flujo() {
    const f = await api("/api/flujo");
    const ult = f.meses.find((m) => m.mes === iso(new Date()).slice(0, 7)) || { entradas: 0, salidas: 0 };
    $("#flujoKpis").innerHTML =
      kpi("Saldo actual", $m(f.saldo_actual), `desde ${$m(f.saldo_inicial)} el ${f.fecha_saldo}`, f.saldo_actual >= 0 ? "pos" : "neg") +
      kpi("Entró este mes", $m(ult.entradas), "", "pos") +
      kpi("Salió este mes", $m(ult.salidas), "", "neg") +
      kpi("Por cobrar", $m(f.por_cobrar), "reservas futuras");
    const meses = f.meses.slice(-12), max = Math.max(...meses.flatMap((m) => [m.entradas, m.salidas]), 1);
    $("#flujoBarras").innerHTML = meses.map((m) =>
      `<div class="mesbar" title="${m.mes}: +${$m(m.entradas)} / −${$m(m.salidas)}"><div class="e" style="height:${(m.entradas / max) * 100}%"></div><div class="s" style="height:${(m.salidas / max) * 100}%"></div><span>${m.mes.slice(2)}</span></div>`).join("");
    $("#flujoMeses").innerHTML = `<tr><th>Mes</th><th class="n">Entradas</th><th class="n">Salidas</th><th class="n">Neto</th><th class="n">Saldo al cierre</th></tr>` +
      [...f.meses].reverse().map((m) => `<tr><td>${m.mes}</td><td class="n pos">${$m(m.entradas)}</td><td class="n neg">${$m(m.salidas)}</td><td class="n">${$m(m.entradas - m.salidas)}</td><td class="n">${$m(m.saldo_final)}</td></tr>`).join("");
    $("#flujoMovs").innerHTML = `<tr><th>Fecha</th><th>Concepto</th><th class="n">Monto</th><th class="n">Saldo</th></tr>` +
      f.movimientos.map((m) => `<tr><td>${m.fecha}</td><td>${esc(m.concepto)}</td><td class="n ${m.monto >= 0 ? "pos" : "neg"}">${$m(m.monto)}</td><td class="n">${$m(m.saldo)}</td></tr>`).join("");
  },

  async reservas() {
    const l = await api("/api/reservas");
    $("#tablaReservas").innerHTML = `<tr><th>Check-in</th><th>Cuarto</th><th>Huésped</th><th class="n">Noches</th><th class="n">Cobrado</th><th class="n">Comisión</th><th class="n">Impuestos</th><th class="n">Neto</th><th>Pago</th><th></th></tr>` +
      l.map((r) => `<tr><td>${r.check_in}</td><td>${esc(cuartoNombre(r.cuarto_id))}</td><td>${esc(r.huesped)}</td><td class="n">${r.noches}</td><td class="n">${$m(r.bruto)}</td><td class="n">${$m(r.comision)}</td><td class="n">${$m(r.impuestos)}</td><td class="n">${$m(r.neto)}</td><td>${r.fecha_pago || ""}</td><td><button class="link" data-del="reservas/${r.id}">Borrar</button></td></tr>`).join("");
  },

  async gastos() {
    const filtro = $("#filtroGastos").value;
    const l = (await api("/api/gastos")).filter((g) =>
      filtro === "todos" || (filtro === "negocio" && !g.personal) || (filtro === "personal" && g.personal) || (filtro === "factura" && g.factura));
    const etiquetas = (g) => (g.factura ? '<span class="tag">Factura</span>' : "") + (g.personal ? '<span class="tag gris">No es del negocio</span>' : "") + (g.de_la_cuenta ? "" : '<span class="tag gris">Otra cuenta</span>');
    const clips = (g) => g.comprobantes.map((c) => `<a class="clip" href="/api/comprobantes/${c.id}" target="_blank" title="${esc(c.nombre_original)}">📎</a>`).join("") +
      `<label class="link-subir" title="Agregar comprobante">＋<input type="file" hidden data-subir="${g.id}" accept="image/*,application/pdf,.xml" multiple></label>`;
    const puedeBorrar = (g) => INI.yo.admin || g.registrado_por === INI.yo.id;
    $("#tablaGastos").innerHTML = `<tr><th>Fecha</th><th>Categoría</th><th>Cuarto</th><th>Descripción</th><th>Registró</th><th>Comprobante</th><th></th><th class="n">Monto</th><th></th></tr>` +
      l.map((g) => `<tr class="${g.personal ? "personal" : ""}"><td>${g.fecha}</td><td>${esc(g.categoria)}</td><td>${g.personal ? "—" : esc(cuartoNombre(g.cuarto_id))}</td><td>${esc(g.descripcion)}</td><td>${esc(g.registrado_por_nombre || "")}</td><td>${clips(g)}</td><td>${etiquetas(g)}</td><td class="n">${$m(g.monto)}</td><td>${puedeBorrar(g) ? `<button class="link" data-del="gastos/${g.id}">Borrar</button>` : ""}</td></tr>`).join("");
  },

  async ajustes() {
    if (INI.yo.admin) {
      const us = await api("/api/usuarios");
      $("#tablaUsuarios").innerHTML = `<tr><th>Nombre</th><th>Usuario</th><th>Tipo</th><th></th></tr>` +
        us.map((u) => `<tr><td>${esc(u.nombre)}</td><td>${esc(u.usuario)}</td><td>${u.admin ? "Administrador" : "Socio"}</td><td>${u.id === INI.yo.id ? "" : `<button class="link" data-del="usuarios/${u.id}">Borrar</button>`}</td></tr>`).join("");
    }
    $("#formSaldo").saldo_inicial.value = INI.config.saldo_inicial;
    $("#formSaldo").fecha_saldo.value = INI.config.fecha_saldo;
    $("#formCuartos").innerHTML = INI.cuartos.map((c) =>
      `<div class="fila" data-id="${c.id}"><input name="nombre" value="${esc(c.nombre)}" placeholder="Nombre"><input name="anuncio_airbnb" value="${esc(c.anuncio_airbnb)}" placeholder="Anuncio en Airbnb"></div>`).join("") + "<button>Guardar cuartos</button>";
  },
};

// ---------------- formularios
document.addEventListener("click", async (e) => {
  const d = e.target.dataset?.del;
  if (!d) return;
  if (!confirm("¿Seguro que quieres borrarlo?")) return;
  try { await api(`/api/${d}`, { method: "DELETE" }); } catch (err) { return toast(err.message); }
  toast("Borrado");
  const t = d.split("/")[0];
  cargar[t === "usuarios" ? "ajustes" : t]();
});

function alEnviar(sel, fn) {
  $(sel).onsubmit = async (e) => {
    e.preventDefault();
    try { await fn(e.target); } catch (err) { toast(err.message); }
  };
}
alEnviar("#formReserva", async (f) => { await post("/api/reservas", formData(f)); f.reset(); toast("Reserva guardada"); cargar.reservas(); });
async function subirComprobantes(gastoId, files) {
  for (const file of files) {
    await api(`/api/gastos/${gastoId}/comprobante`, {
      method: "POST",
      headers: { "Content-Type": file.type || (file.name.endsWith(".xml") ? "application/xml" : "application/octet-stream"), "X-Nombre": encodeURIComponent(file.name) },
      body: file,
    });
  }
}
document.addEventListener("change", async (e) => {
  const id = e.target.dataset?.subir;
  if (!id) return;
  try { await subirComprobantes(id, e.target.files); toast("Comprobante subido"); } catch (err) { toast(err.message); }
  cargar.gastos();
});
alEnviar("#formGasto", async (f) => {
  const archivos = [...f.comprobante.files];
  const datos = formData(f); delete datos.comprobante;
  const r = await post("/api/gastos", datos);
  if (archivos.length) await subirComprobantes(r.id, archivos);
  const fch = f.fecha.value; f.reset(); f.fecha.value = fch;
  f.cuarto_id.disabled = false;
  toast("Gasto guardado"); cargar.gastos();
});
alEnviar("#formMov", async (f) => { await post("/api/movimientos", formData(f)); f.reset(); toast("Ajuste agregado"); cargar.flujo(); });
alEnviar("#formUsuario", async (f) => { await post("/api/usuarios", formData(f)); f.reset(); toast("Usuario agregado"); cargar.ajustes(); });
alEnviar("#formPassword", async (f) => { await post("/api/password", formData(f)); f.reset(); toast("Contraseña cambiada"); });
alEnviar("#formSaldo", async (f) => { await post("/api/config", formData(f)); await iniciar(); toast("Saldo guardado"); });
alEnviar("#formCuartos", async (f) => {
  const datos = $$(".fila", f).map((fila) => ({ id: +fila.dataset.id, nombre: $("[name=nombre]", fila).value, anuncio_airbnb: $("[name=anuncio_airbnb]", fila).value }));
  await post("/api/cuartos", datos); await iniciar(); toast("Cuartos guardados");
});

async function subir(input, url, salida, msg) {
  input.onchange = async () => {
    const file = input.files[0];
    if (!file) return;
    try {
      const r = await post(url, await file.text());
      $(salida).textContent = msg(r);
      await iniciar();
    } catch (err) { $(salida).textContent = "Error: " + err.message; }
    input.value = "";
  };
}
subir($("#fileAirbnb"), "/api/importar/airbnb", "#resAirbnb", (r) => `Listo: ${r.nuevas} reservas nuevas y ${r.actualizadas} actualizadas (${r.filas} filas leídas).`);
subir($("#fileGastos"), "/api/importar/gastos", "#resGastos", (r) => `Listo: ${r.importados} gastos importados${r.omitidos ? `, ${r.omitidos} filas omitidas (sin fecha o monto)` : ""}.`);

const descargar = (que) => { location.href = `/api/exportar/${que}?desde=${$("#expDesde").value}&hasta=${$("#expHasta").value}`; };
$("#expReservas").onclick = () => descargar("reservas");
$("#expGastos").onclick = () => descargar("gastos");
$("#filtroGastos").onchange = () => cargar.gastos();
$("#formGasto").personal.onchange = (e) => { const c = $("#formGasto").cuarto_id; c.disabled = e.target.checked; if (e.target.checked) c.value = ""; };

// ---------------- sesión
function mostrarLogin(primerUso) {
  $("#login").hidden = false;
  $("#login").classList.toggle("primer-uso", primerUso);
  $("#formLogin").nombre.required = primerUso;
  $("#loginAyuda").textContent = primerUso ? "Primera vez: crea tu usuario de administrador." : "Entra con tu usuario.";
  $("#btnLogin").textContent = primerUso ? "Crear y entrar" : "Entrar";
}
$("#formLogin").onsubmit = async (e) => {
  e.preventDefault();
  const primer = $("#login").classList.contains("primer-uso");
  try {
    await post(primer ? "/api/primer-usuario" : "/api/login", formData(e.target));
    $("#login").hidden = true; e.target.reset(); $("#loginError").textContent = "";
    iniciar();
  } catch (err) { $("#loginError").textContent = err.message; }
};
$("#salir").onclick = async () => { await post("/api/logout", {}); location.reload(); };

// ---------------- inicio
async function iniciar() {
  INI = await api("/api/inicio");
  $("#quien").textContent = `${INI.yo.nombre}${INI.yo.admin ? " · admin" : ""}`;
  document.body.classList.toggle("socio", !INI.yo.admin);
  const activa0 = $("#tabs .activo");
  if (!INI.yo.admin && (activa0.classList.contains("solo-admin") || !iniciar.listo)) { iniciar.listo = true; return $("[data-tab=gastos]").click(); }
  iniciar.listo = true;
  $$(".selCuarto").forEach((s) => {
    const general = s.closest("#formGasto") ? '<option value="">General (toda la casa)</option>' : "";
    s.innerHTML = general + INI.cuartos.map((c) => `<option value="${c.id}">${esc(c.nombre)}</option>`).join("");
  });
  $("#selCategoria").innerHTML = INI.categorias.map((c) => `<option>${c}</option>`).join("");
  const activa = $("#tabs .activo").dataset.tab;
  cargar[activa]?.();
}
(() => {
  const h = new Date();
  $("#formGasto").fecha.value = iso(h);
  $("#formMov").fecha.value = iso(h);
  $("#expDesde").value = iso(new Date(h.getFullYear(), h.getMonth() - 1, 1));
  $("#expHasta").value = iso(new Date(h.getFullYear(), h.getMonth(), 0));
  api("/api/estado").then((e) => (e.usuario ? iniciar() : mostrarLogin(!e.hay_usuarios)));
})();
