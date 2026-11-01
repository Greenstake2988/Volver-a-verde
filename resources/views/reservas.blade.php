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
  <table class="tabla">
    <tr><th>Check-in</th><th>Cuarto</th><th>Huésped</th><th class="n">Noches</th><th class="n">Cobrado</th><th class="n">Comisión</th><th class="n">Impuestos</th><th class="n">Neto</th><th>Pago</th><th></th></tr>
    @foreach ($reservas as $r)
      <tr><td>{{ $r->check_in->toDateString() }}</td><td>{{ $r->cuarto->nombre ?? '' }}</td><td>{{ $r->huesped }}</td><td class="n">{{ $r->noches }}</td>
        <td class="n">{{ dinero($r->bruto) }}</td><td class="n">{{ dinero($r->comision) }}</td><td class="n">{{ dinero($r->impuestos) }}</td><td class="n">{{ dinero($r->neto) }}</td>
        <td>{{ $r->fecha_pago?->toDateString() }}</td>
        <td>@can('admin')<form method="post" action="{{ route('reservas.destroy', $r) }}" class="inline" data-confirmar="¿Borrar esta reserva?">@csrf @method('delete')<button class="link">Borrar</button></form>@endcan</td></tr>
    @endforeach
  </table>
  <div class="paginacion">{{ $reservas->links('partials.paginacion') }}</div>
</div>
@endsection
