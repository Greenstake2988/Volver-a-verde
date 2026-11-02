@extends('layouts.app', ['titulo' => 'Flujo de efectivo'])
@section('contenido')
@php($mesActual = now()->format('Y-m'))
@php($este = collect($f['meses'])->firstWhere('mes', $mesActual) ?? ['entradas' => 0, 'salidas' => 0])
<div class="kpis">
  <div class="kpi"><div class="t">Saldo actual</div><div class="v {{ $f['saldo_actual'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($f['saldo_actual']) }}</div><div class="s">desde {{ dinero($f['saldo_inicial']) }} el {{ $f['fecha_saldo'] }}</div></div>
  <div class="kpi"><div class="t">Entró este mes</div><div class="v pos">{{ dinero($este['entradas']) }}</div></div>
  <div class="kpi"><div class="t">Salió este mes</div><div class="v neg">{{ dinero($este['salidas']) }}</div></div>
  <div class="kpi"><div class="t">Por cobrar</div><div class="v">{{ dinero($f['por_cobrar']) }}</div><div class="s">reservas futuras</div></div>
</div>

<div class="card">
  <h2>Entradas y salidas por mes</h2>
  @php($ult = array_slice($f['meses'], -12))
  @php($max = max(array_merge([1], array_column($ult, 'entradas'), array_column($ult, 'salidas'))))
  <div class="barras">
    @foreach ($ult as $m)
      <div class="mesbar" title="{{ $m['mes'] }}: +{{ dinero($m['entradas']) }} / −{{ dinero($m['salidas']) }}">
        <div class="e" style="height: {{ $m['entradas'] / $max * 100 }}%"></div><div class="s" style="height: {{ $m['salidas'] / $max * 100 }}%"></div>
        <span>{{ substr($m['mes'], 2) }}</span>
      </div>
    @endforeach
  </div>
  <table class="tabla">
    <tr><th>Mes</th><th class="n">Entradas</th><th class="n">Salidas</th><th class="n">Neto</th><th class="n">Saldo al cierre</th></tr>
    @foreach (array_reverse($f['meses']) as $m)
      <tr><td>{{ $m['mes'] }}</td><td class="n pos">{{ dinero($m['entradas']) }}</td><td class="n neg">{{ dinero($m['salidas']) }}</td><td class="n">{{ dinero($m['entradas'] - $m['salidas']) }}</td><td class="n">{{ dinero($m['saldo_final']) }}</td></tr>
    @endforeach
  </table>
</div>

@can('admin')
<div class="card">
  <h2>Ajustar con el banco</h2>
  <p class="ayuda">Si el saldo no coincide con tu estado de cuenta (comisiones bancarias, retiros, dinero que metiste), registra aquí la diferencia. Positivo = entró, negativo = salió.</p>
  <form method="post" action="{{ route('movimientos.store') }}" class="fila">
    @csrf
    <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
    <input type="number" step="0.01" name="monto" placeholder="Monto (+/−)" required>
    <input name="concepto" placeholder="Concepto">
    <button>Agregar</button>
  </form>
</div>
@endcan

<div class="card">
  <h2>Movimientos</h2>
  <table class="tabla">
    <tr><th>Fecha</th><th>Concepto</th><th class="n">Monto</th><th class="n">Saldo</th></tr>
    @foreach ($f['movimientos'] as $m)
      <tr><td>{{ $m['fecha'] }}</td><td>{{ $m['concepto'] }}</td><td class="n {{ $m['monto'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($m['monto']) }}</td><td class="n">{{ dinero($m['saldo']) }}</td></tr>
    @endforeach
  </table>
</div>
@endsection
