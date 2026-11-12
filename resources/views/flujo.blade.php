@extends('layouts.app', ['titulo' => 'Flujo de efectivo'])
@section('contenido')
@php($mesActual = now()->format('Y-m'))
@php($este = collect($f['meses'])->firstWhere('mes', $mesActual) ?? ['entradas' => 0, 'salidas' => 0])
<div class="kpis">
  <div class="kpi"><div class="t">Disponible real</div><div class="v {{ $f['disponible'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($f['disponible']) }}</div>
    <div class="s">bancos + caja chica − tarjetas − por reembolsar</div></div>
  <div class="kpi"><div class="t">Entró este mes</div><div class="v pos">{{ dinero($este['entradas']) }}</div></div>
  <div class="kpi"><div class="t">Salió este mes</div><div class="v neg">{{ dinero($este['salidas']) }}</div><div class="s">incluye compras con tarjeta</div></div>
  <div class="kpi"><div class="t">Por cobrar</div><div class="v">{{ dinero($f['por_cobrar']) }}</div><div class="s">reservas futuras</div></div>
  @if ($f['total_nos_deben'] > 0)
    <div class="kpi"><div class="t">Socios le deben al negocio</div><div class="v">{{ dinero($f['total_nos_deben']) }}</div><div class="s">gastos personales con tarjetas del negocio</div></div>
  @endif
</div>

<div class="cuentas">
  @foreach ($f['cuentas'] as $c)
    <div class="kpi">
      <div class="t">{{ $c['nombre'] }}</div>
      @if ($c['tipo'] === 'credito')
        <div class="v {{ $c['saldo'] < 0 ? 'neg' : 'pos' }}">{{ $c['saldo'] < 0 ? 'Debes '.dinero(-$c['saldo']) : dinero($c['saldo']) }}</div>
        <div class="s">tarjeta de crédito</div>
      @elseif ($c['tipo'] === 'efectivo')
        <div class="v {{ $c['saldo'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($c['saldo']) }}</div>
        <div class="s">efectivo que debe haber en la caja</div>
      @else
        <div class="v {{ $c['saldo'] >= 0 ? 'pos' : 'neg' }}">{{ dinero($c['saldo']) }}</div>
        <div class="s">saldo desde el {{ $c['fecha_saldo'] }}</div>
      @endif
    </div>
  @endforeach
  @foreach ($f['nos_deben'] as $p)
    <div class="kpi"><div class="t">{{ $p['nombre'] }} le debe al negocio</div><div class="v">{{ dinero($p['monto']) }}</div><div class="s">gastos personales con cuentas del negocio</div></div>
  @endforeach
  @foreach ($f['por_reembolsar'] as $p)
    <div class="kpi"><div class="t">Por reembolsar a {{ $p['nombre'] }}</div><div class="v neg">{{ dinero($p['monto']) }}</div><div class="s">pagó con su dinero</div></div>
  @endforeach
</div>

@if ($caja)
@can('admin')
<div class="card">
  <h2>{{ $caja->nombre }}</h2>
  <p class="ayuda">El efectivo del negocio. Cuando sacas dinero del banco para la caja es un traspaso, no un gasto. Lo que se paga en efectivo se captura en Gastos eligiendo “{{ $caja->nombre }}” en “¿Con qué se pagó?”.</p>
  <div class="grid2">
    <div>
      <h3>Meter dinero a la caja</h3>
      <form method="post" action="{{ route('transferencias.store') }}" class="fila">
        @csrf
        <input type="hidden" name="destino_id" value="{{ $caja->id }}">
        <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
        <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
        <label>Sacado de <select name="origen_id">@foreach ($cuentas->reject(fn ($c) => $c->id === $caja->id || $c->esCredito()) as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select></label>
        <button>Registrar retiro</button>
      </form>
    </div>
    <div>
      <h3>Entró efectivo</h3>
      <p class="ayuda">Otros ingresos en efectivo. Si es la renta de un cuarto, mejor captúrala en Reservas → “Renta en efectivo” para que cuente en la utilidad del cuarto.</p>
      <form method="post" action="{{ route('movimientos.store') }}" class="fila">
        @csrf
        <input type="hidden" name="cuenta_id" value="{{ $caja->id }}">
        <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
        <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
        <input name="concepto" placeholder="Concepto">
        <button>Registrar entrada</button>
      </form>
    </div>
    <div>
      <h3>Contar la caja</h3>
      <p class="ayuda">Pon cuánto efectivo hay de verdad. Si no cuadra, la diferencia queda como ajuste.</p>
      <form method="post" action="{{ route('caja.contar') }}" class="fila">
        @csrf
        <input type="hidden" name="cuenta_id" value="{{ $caja->id }}">
        <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
        <input type="number" step="0.01" min="0" name="contado" placeholder="Hay en la caja" required>
        <button>Contar</button>
      </form>
    </div>
    <div>
      <h3>Depositar al banco</h3>
      <p class="ayuda">Si sobra efectivo y lo llevas al banco.</p>
      <form method="post" action="{{ route('transferencias.store') }}" class="fila">
        @csrf
        <input type="hidden" name="origen_id" value="{{ $caja->id }}">
        <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
        <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
        <label>A <select name="destino_id">@foreach ($cuentas->reject(fn ($c) => $c->id === $caja->id || $c->esCredito()) as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select></label>
        <button>Registrar depósito</button>
      </form>
    </div>
  </div>
</div>
@endcan
@endif

@can('admin')
<div class="grid2">
  <div class="card">
    <h2>Pagar la tarjeta</h2>
    <p class="ayuda">Pasa dinero de una cuenta a otra. Baja el débito y la deuda de la tarjeta; no cuenta como gasto otra vez.</p>
    <form method="post" action="{{ route('transferencias.store') }}" class="fila">
      @csrf
      <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
      <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
      <label>De <select name="origen_id">@foreach ($cuentas as $c)<option value="{{ $c->id }}" @selected($c->tipo === 'debito')>{{ $c->nombre }}</option>@endforeach</select></label>
      <label>A <select name="destino_id">@foreach ($cuentas as $c)<option value="{{ $c->id }}" @selected($c->tipo === 'credito')>{{ $c->nombre }}</option>@endforeach</select></label>
      <button>Registrar pago</button>
    </form>
  </div>
  <div class="card">
    <h2>Un socio paga al negocio</h2>
    <p class="ayuda">Cuando un socio repone lo que cargó de gastos personales a una tarjeta o cuenta del negocio.</p>
    <form method="post" action="{{ route('abonos.store') }}" class="fila">
      @csrf
      <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
      <select name="user_id">
        @foreach ($usuarios as $u)
          @php($debe = collect($f['nos_deben'])->firstWhere('user_id', $u->id))
          <option value="{{ $u->id }}">{{ $u->name }}{{ $debe ? ' (debe '.dinero($debe['monto']).')' : '' }}</option>
        @endforeach
      </select>
      <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
      <label>A <select name="cuenta_id">@foreach ($cuentas as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select></label>
      <button>Registrar pago</button>
    </form>
  </div>
  @if ($f['por_reembolsar'])
  <div class="card">
    <h2>Reembolsar a un socio</h2>
    <p class="ayuda">Cuando le devuelves a un socio lo que pagó con su dinero.</p>
    <form method="post" action="{{ route('reembolsos.store') }}" class="fila">
      @csrf
      <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
      <select name="reembolso_a">
        @foreach ($usuarios as $u)
          @php($pend = collect($f['por_reembolsar'])->firstWhere('user_id', $u->id))
          <option value="{{ $u->id }}">{{ $u->name }}{{ $pend ? ' (se le deben '.dinero($pend['monto']).')' : '' }}</option>
        @endforeach
      </select>
      <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" required>
      <label>Desde <select name="origen_id">@foreach ($cuentas as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select></label>
      <button>Registrar reembolso</button>
    </form>
  </div>
  @endif
</div>
@endcan

<div class="card">
  <h2>Entradas y salidas por mes</h2>
  <p class="ayuda">Todas las cuentas juntas, con la caja chica. Los pagos de tarjeta y los retiros para la caja no aparecen porque solo pasan dinero de una cuenta a otra.</p>
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
    <tr><th>Mes</th><th class="n">Entradas</th><th class="n">Salidas</th><th class="n">Neto</th><th class="n">Posición al cierre</th></tr>
    @foreach (array_reverse($f['meses']) as $m)
      <tr><td>{{ $m['mes'] }}</td><td class="n pos">{{ dinero($m['entradas']) }}</td><td class="n neg">{{ dinero($m['salidas']) }}</td><td class="n">{{ dinero($m['entradas'] - $m['salidas']) }}</td><td class="n">{{ dinero($m['saldo_final']) }}</td></tr>
    @endforeach
  </table>
</div>

@can('admin')
<div class="card">
  <h2>Ajustar con el banco</h2>
  <p class="ayuda">Si el saldo de una cuenta no coincide con tu estado de cuenta (comisiones, intereses, retiros, dinero que metiste), registra la diferencia. Positivo = entró, negativo = salió.</p>
  <form method="post" action="{{ route('movimientos.store') }}" class="fila">
    @csrf
    <select name="cuenta_id">@foreach ($cuentas as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select>
    <input type="date" name="fecha" value="{{ now()->toDateString() }}" required>
    <input type="number" step="0.01" name="monto" placeholder="Monto (+/−)" required>
    <input name="concepto" placeholder="Concepto">
    <button>Agregar</button>
  </form>
</div>
@endcan

<div class="card">
  <h2>Movimientos</h2>
  <p class="ayuda">Los pagos de tarjeta y los traspasos a la caja chica salen en gris en un solo renglón: pasan dinero de una cuenta a otra y no cuentan como gasto. Los gastos se borran desde Gastos.</p>
  <table class="tabla">
    <tr><th>Fecha</th><th>Cuenta</th><th>Concepto</th><th class="n">Monto</th><th></th></tr>
    @foreach ($f['movimientos'] as $m)
      <tr class="{{ $m['interno'] ? 'personal' : '' }}"><td>{{ $m['fecha'] }}</td><td>{{ $m['cuenta'] }}</td><td>{{ $m['concepto'] }}</td>
        <td class="n {{ $m['interno'] ? '' : ($m['monto'] >= 0 ? 'pos' : 'neg') }}">{{ dinero($m['monto']) }}</td>
        <td>
          @can('admin')
            @if (! empty($m['transferencia_id']))
              <form method="post" action="{{ route('transferencias.destroy', $m['transferencia_id']) }}" class="inline" data-confirmar="¿Borrar este {{ $m['interno'] ? 'traspaso' : 'reembolso' }}? El dinero vuelve a la cuenta de origen.">@csrf @method('delete')<button class="link">Borrar</button></form>
            @elseif (! empty($m['movimiento_id']))
              <form method="post" action="{{ route('movimientos.destroy', $m['movimiento_id']) }}" class="inline" data-confirmar="¿Borrar este movimiento?">@csrf @method('delete')<button class="link">Borrar</button></form>
            @endif
          @endcan
          @if (! empty($m['gasto_id']))<a class="link" href="{{ route('gastos.index') }}">ver en Gastos</a>@endif
        </td></tr>
    @endforeach
  </table>
</div>
@endsection
