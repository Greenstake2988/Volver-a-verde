@extends('layouts.app', ['titulo' => 'Resumen'])
@section('contenido')
@php($t = $r['totales'])
<form class="filtros {{ $periodo === 'custom' ? 'custom-on' : '' }}" method="get">
  <label>Periodo
    <select name="periodo" onchange="this.form.classList.toggle('custom-on', this.value==='custom'); if (this.value!=='custom') this.form.submit()">
      @foreach (['mes' => 'Este mes', 'mes-1' => 'Mes pasado', '3m' => 'Últimos 3 meses', 'anio' => 'Este año', '12m' => 'Últimos 12 meses', 'custom' => 'Personalizado…'] as $v => $txt)
        <option value="{{ $v }}" @selected($periodo === $v)>{{ $txt }}</option>
      @endforeach
    </select>
  </label>
  <label class="custom">Desde <input type="date" name="desde" value="{{ $r['desde']->toDateString() }}"></label>
  <label class="custom">Hasta <input type="date" name="hasta" value="{{ $r['hasta']->toDateString() }}"></label>
  <button class="custom">Ver</button>
  <span class="ayuda">{{ $r['desde']->toDateString() }} a {{ $r['hasta']->toDateString() }}</span>
</form>

<div class="veredicto {{ $conclusion['nivel'] }}">
  <b>{{ $conclusion['titulo'] }}</b>
  @if ($conclusion['notas'])<ul>@foreach ($conclusion['notas'] as $n)<li>{{ $n }}</li>@endforeach</ul>@endif
</div>

<div class="kpis">
  <div class="kpi"><div class="t">Utilidad neta</div><div class="v {{ $t['utilidad'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($t['utilidad']) }}</div><div class="s">margen {{ pct($t['margen']) }}</div></div>
  <div class="kpi"><div class="t">Depositado por Airbnb</div><div class="v">{{ dinero($t['neto']) }}</div><div class="s">{{ $t['reservas'] }} reservas</div></div>
  <div class="kpi"><div class="t">Gastos del negocio</div><div class="v">{{ dinero($t['gastos']) }}</div>
    <div class="s">@if ($t['gastos_personales']) + {{ dinero($t['gastos_personales']) }} que no son del negocio (no cuentan) @endif</div></div>
  <div class="kpi"><div class="t">Ocupación</div><div class="v">{{ pct($t['ocupacion']) }}</div><div class="s">{{ $t['noches'] }} de {{ $r['dias'] * $r['n_cuartos'] }} noches</div></div>
  <div class="kpi"><div class="t">Tarifa promedio</div><div class="v">{{ dinero($t['tarifa_promedio']) }}</div><div class="s">por noche (bruto)</div></div>
</div>

<div class="grid2">
  <div class="card">
    <h2>¿De dónde sale la utilidad?</h2>
    <table class="tabla">
      <tr><td>Lo que pagaron los huéspedes</td><td class="n">{{ dinero($t['bruto']) }}</td></tr>
      <tr><td>− Comisión de Airbnb</td><td class="n neg">{{ dinero(-$t['comision']) }}</td></tr>
      <tr><td>− Impuestos retenidos por Airbnb</td><td class="n neg">{{ dinero(-$t['impuestos']) }}</td></tr>
      <tr class="total"><td>= Depositado</td><td class="n">{{ dinero($t['neto']) }}</td></tr>
      <tr><td>− Gastos de operación</td><td class="n neg">{{ dinero(-$t['gastos']) }}</td></tr>
      <tr class="total"><td>= Utilidad neta</td><td class="n {{ $t['utilidad'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($t['utilidad']) }}</td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Gastos por categoría</h2>
    @php($max = max($r['por_categoria'] ?: [1]))
    @forelse ($r['por_categoria'] as $cat => $monto)
      <div class="barra-cat"><span>{{ $cat }}</span><div class="b" style="width: {{ $monto / $max * 100 }}%"></div><span class="m">{{ dinero($monto) }}</span></div>
    @empty
      <p class="ayuda">Sin gastos en este periodo.</p>
    @endforelse
  </div>
</div>

<div class="card">
  <h2>Rentabilidad por cuarto</h2>
  <p class="ayuda">Los gastos generales (luz, agua, limpieza…) se reparten igual entre los cuartos. Los gastos marcados con un cuarto se le cargan solo a ese cuarto.</p>
  <table class="tabla">
    <tr><th>Cuarto</th><th class="n">Noches</th><th class="n">Ocupación</th><th class="n">Tarifa prom.</th><th class="n">Depositado</th><th class="n">Gastos propios</th><th class="n">Gastos generales</th><th class="n">Utilidad</th><th class="n">Margen</th></tr>
    @foreach ($r['por_cuarto'] as $c)
      <tr><td>{{ $c['nombre'] }}</td><td class="n">{{ $c['noches'] }}</td><td class="n">{{ pct($c['ocupacion']) }}</td><td class="n">{{ dinero($c['tarifa_promedio']) }}</td>
        <td class="n">{{ dinero($c['neto']) }}</td><td class="n">{{ dinero($c['gastos_directos']) }}</td><td class="n">{{ dinero($c['gastos_generales']) }}</td>
        <td class="n {{ $c['utilidad'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($c['utilidad']) }}</td><td class="n">{{ pct($c['margen']) }}</td></tr>
    @endforeach
  </table>
</div>
@endsection
