@extends('layouts.app', ['titulo' => 'Reservas'])
@section('contenido')
@can('admin')
<div class="card">
  <h2>Agregar reserva</h2>
  <p class="ayuda">Lo más fácil es importar el CSV de Airbnb en “Importar / Contador”. Aquí puedes capturar una a mano.</p>
  <form method="post" action="{{ route('reservas.store') }}" class="fila">
    @csrf
    <select name="cuarto_id" required>@foreach ($cuartos as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select>
    <input name="huesped" placeholder="Huésped" value="{{ old('huesped') }}">
    <label>Check-in <input type="date" name="check_in" value="{{ old('check_in') }}" required></label>
    <input type="number" name="noches" min="1" placeholder="Noches" value="{{ old('noches') }}" required>
    <input type="number" step="0.01" name="bruto" placeholder="Total cobrado" value="{{ old('bruto') }}" required>
    <input type="number" step="0.01" name="comision" placeholder="Comisión Airbnb" value="{{ old('comision') }}">
    <input type="number" step="0.01" name="impuestos" placeholder="Impuestos retenidos" value="{{ old('impuestos') }}">
    <label>Pago <input type="date" name="fecha_pago" value="{{ old('fecha_pago') }}"></label>
    <input name="codigo" placeholder="Código" value="{{ old('codigo') }}">
    <button>Guardar</button>
  </form>
</div>
@endcan
<div class="card">
  @php
    $hoy = now();
    $rapidos = [
      'Este mes' => [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()],
      'Mes pasado' => [$hoy->copy()->subMonthNoOverflow()->startOfMonth(), $hoy->copy()->subMonthNoOverflow()->endOfMonth()],
      'Este año' => [$hoy->copy()->startOfYear(), $hoy->copy()->endOfYear()],
      'Año pasado' => [$hoy->copy()->subYear()->startOfYear(), $hoy->copy()->subYear()->endOfYear()],
    ];
  @endphp
  <form method="get" action="{{ route('reservas.index') }}" class="filtros">
    <select name="cuarto_id">
      <option value="">Todos los cuartos</option>
      @foreach ($todosCuartos as $c)<option value="{{ $c->id }}" @selected($f['cuarto_id'] == $c->id)>{{ $c->nombre }}{{ $c->activo ? '' : ' (inactivo)' }}</option>@endforeach
    </select>
    <label>Check-in desde <input type="date" name="desde" value="{{ $f['desde'] }}"></label>
    <label>hasta <input type="date" name="hasta" value="{{ $f['hasta'] }}"></label>
    <button>Filtrar</button>
    @if ($f['cuarto_id'] || $f['desde'] || $f['hasta'])<a href="{{ route('reservas.index') }}">Quitar filtros</a>@endif
  </form>
  <p class="ayuda">Rápido:
    @foreach ($rapidos as $txt => [$d, $h])
      <a href="{{ route('reservas.index', array_filter(['cuarto_id' => $f['cuarto_id'], 'desde' => $d->toDateString(), 'hasta' => $h->toDateString()])) }}">{{ $txt }}</a>@if (! $loop->last) · @endif
    @endforeach
    @can('admin')
      @if ($f['desde'] && $f['hasta'])
        · <a href="{{ route('datos.exportar', ['tipo' => 'reservas'] + array_filter($f)) }}">Descargar CSV de esta selección</a>
      @else
        · Elige fechas para descargar el CSV
      @endif
    @endcan
  </p>
  <div class="kpis">
    <div class="kpi"><div class="t">Reservas</div><div class="v">{{ $tot->reservas }}</div><div class="s">{{ $tot->noches }} noches</div></div>
    <div class="kpi"><div class="t">Cobrado (sin IVA)</div><div class="v">{{ dinero($tot->bruto) }}</div><div class="s">{{ $tot->noches ? dinero($tot->bruto / $tot->noches).' por noche' : '' }}</div></div>
    <div class="kpi"><div class="t">Comisión Airbnb</div><div class="v">{{ dinero($tot->comision) }}</div></div>
    <div class="kpi"><div class="t">IVA cobrado / retenciones</div><div class="v">{{ dinero($tot->iva) }}</div><div class="s">− {{ dinero($tot->impuestos) }} retenido</div></div>
    <div class="kpi"><div class="t">Neto depositado</div><div class="v">{{ dinero($neto) }}</div><div class="s">ISH pagado por Airbnb: {{ dinero($tot->ish) }}</div></div>
  </div>
  <table class="tabla">
    <tr><th>Check-in</th><th>Cuarto</th><th>Huésped</th><th class="n">Noches</th><th class="n">Cobrado</th><th class="n">IVA</th><th class="n">Comisión</th><th class="n">Retenciones</th><th class="n">Neto</th><th>Pago</th><th></th></tr>
    @foreach ($reservas as $r)
      <tr><td class="nw">{{ $r->check_in->toDateString() }}</td><td class="nw">{{ $r->cuarto->nombre ?? '' }}</td><td>{{ $r->huesped }}</td><td class="n">{{ $r->noches }}</td>
        <td class="n">{{ dinero($r->bruto) }}</td><td class="n">{{ dinero($r->iva_trasladado) }}</td><td class="n">{{ dinero($r->comision) }}</td><td class="n">{{ dinero($r->impuestos) }}</td><td class="n">{{ dinero($r->neto) }}</td>
        <td class="nw">{{ $r->fecha_pago?->toDateString() }}</td>
        <td>@can('admin')<form method="post" action="{{ route('reservas.destroy', $r) }}" class="inline" data-confirmar="¿Borrar esta reserva?">@csrf @method('delete')<button class="link">Borrar</button></form>@endcan</td></tr>
    @endforeach
  </table>
  <div class="paginacion">{{ $reservas->links('partials.paginacion') }}</div>
</div>
@endsection
