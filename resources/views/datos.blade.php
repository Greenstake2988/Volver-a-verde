@extends('layouts.app', ['titulo' => 'Importar / Contador'])
@section('contenido')
<div class="grid2">
  <div class="card">
    <h2>Importar reservas de Airbnb</h2>
    <p class="ayuda">En Airbnb: <b>Menú → Ganancias → Historial de transacciones → Descargar CSV</b>. Puedes subirlo varias veces; las reservas repetidas se actualizan, no se duplican.</p>
    <form method="post" action="{{ route('datos.airbnb') }}" enctype="multipart/form-data">
      @csrf<input type="file" name="archivo" accept=".csv" required data-autoenviar>
    </form>
  </div>
  <div class="card">
    <h2>Importar gastos desde Excel</h2>
    <p class="ayuda">En Excel: <b>Archivo → Guardar como → CSV</b>. Columnas: <code>fecha, monto, categoria</code> y opcionales <code>descripcion, cuarto, factura, personal, pagado con</code>. <code>factura</code> y <code>personal</code> van con “si” o “no”; <code>pagado con</code> es el nombre de la cuenta, “crédito”, “débito” o “propio”, y si va vacío se usa la de débito.</p>
    <form method="post" action="{{ route('datos.gastos') }}" enctype="multipart/form-data">
      @csrf<input type="file" name="archivo" accept=".csv" required data-autoenviar>
    </form>
  </div>
</div>
<div class="card">
  <h2>Reporte para el contador</h2>
  <p class="ayuda">Descarga las reservas (con comisiones e impuestos retenidos) y los gastos del periodo. Se abren directo en Excel. Los comprobantes se ven en la pestaña Gastos.</p>
  <form method="get" class="fila" id="formExportar">
    <label>Desde <input type="date" name="desde" value="{{ $desde }}" required></label>
    <label>Hasta <input type="date" name="hasta" value="{{ $hasta }}" required></label>
    <button formaction="{{ route('datos.exportar', 'reservas') }}">Descargar reservas</button>
    <button formaction="{{ route('datos.exportar', 'gastos') }}">Descargar gastos</button>
  </form>
</div>
@endsection
