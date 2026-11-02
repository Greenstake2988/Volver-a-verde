@extends('layouts.app', ['titulo' => 'Gastos'])
@section('contenido')
<div class="card">
  <h2>Agregar gasto</h2>
  <form method="post" action="{{ route('gastos.store') }}" enctype="multipart/form-data" class="fila" id="formGasto">
    @csrf
    <input type="date" name="fecha" value="{{ old('fecha', now()->toDateString()) }}" required>
    <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" value="{{ old('monto') }}" required inputmode="decimal">
    <select name="categoria" required>
      @foreach (\App\Models\Gasto::CATEGORIAS as $c)<option @selected(old('categoria') === $c)>{{ $c }}</option>@endforeach
    </select>
    <select name="cuarto_id"><option value="">General (toda la casa)</option>
      @foreach ($cuartos as $c)<option value="{{ $c->id }}" @selected(old('cuarto_id') == $c->id)>{{ $c->nombre }}</option>@endforeach
    </select>
    <input name="descripcion" placeholder="Descripción" value="{{ old('descripcion') }}">
    @can('admin')
      <label>¿Quién lo pagó?
        <select name="user_id">
          @foreach ($usuarios as $u)
            <option value="{{ $u->id }}" @selected(old('user_id', auth()->id()) == $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </label>
    @endcan
    <label class="check"><input type="checkbox" name="factura" value="1" @checked(old('factura'))> Tiene factura</label>
    <label class="check"><input type="checkbox" name="personal" value="1" @checked(old('personal'))> No es del negocio</label>
    {{-- Por defecto: el admin paga con la primera cuenta; un socio, con su dinero. --}}
    @php($pagoDefault = old('cuenta_id', auth()->user()->admin ? (string) $cuentas->first()?->id : ''))
    <label>¿Con qué se pagó?
      <select name="cuenta_id">
        @foreach ($cuentas as $c)
          <option value="{{ $c->id }}" @selected((string) $pagoDefault === (string) $c->id)>{{ $c->nombre }}</option>
        @endforeach
        <option value="" @selected((string) $pagoDefault === '')>Dinero propio (por reembolsar)</option>
      </select>
    </label>
    <label class="archivo">📎 Comprobante (foto, PDF o XML)
      <input type="file" name="comprobantes[]" accept="image/*,application/pdf,.xml" multiple>
    </label>
    <button>Guardar</button>
  </form>
  <p class="ayuda">“No es del negocio”: el gasto queda registrado y sale en el reporte para el contador, pero no se resta en la utilidad ni en la rentabilidad de los cuartos. Si lo pagaste con una cuenta del negocio, sí mueve el saldo de esa cuenta en el flujo de efectivo. Qué facturas se pueden deducir lo decide el contador.</p>
</div>

<div class="card">
  <form method="get" action="{{ route('gastos.index') }}" class="filtros">
    <select name="user_id">
      <option value="">Todos los socios</option>
      @foreach ($socios as $u)<option value="{{ $u->id }}" @selected($f['user_id'] == $u->id)>{{ $u->name }}</option>@endforeach
    </select>
    <select name="filtro">
      @foreach (['todos' => 'Todos los gastos', 'mios' => 'Los que yo pagué', 'negocio' => 'Solo del negocio', 'personal' => 'Solo los que no son del negocio', 'propio' => 'Pagados con dinero propio', 'factura' => 'Solo con factura', 'sin-comprobante' => 'Sin comprobante'] as $v => $txt)
        <option value="{{ $v }}" @selected($filtro === $v)>{{ $txt }}</option>
      @endforeach
    </select>
    <label>Desde <input type="date" name="desde" value="{{ $f['desde'] }}"></label>
    <label>hasta <input type="date" name="hasta" value="{{ $f['hasta'] }}"></label>
    <button>Filtrar</button>
    @if ($f['user_id'] || $f['desde'] || $f['hasta'] || $filtro !== 'todos')<a href="{{ route('gastos.index') }}">Quitar filtros</a>@endif
  </form>
  <p class="ayuda">Rápido:
    @foreach ($rapidos as $txt => [$d, $h])
      <a href="{{ route('gastos.index', $base + ['desde' => $d->toDateString(), 'hasta' => $h->toDateString()]) }}">{{ $txt }}</a>@if (! $loop->last) · @endif
    @endforeach
    @can('admin')
      @if ($f['desde'] && $f['hasta'])
        · <a href="{{ route('datos.exportar', ['tipo' => 'gastos'] + $base + ['desde' => $f['desde'], 'hasta' => $f['hasta']]) }}">Descargar CSV de esta selección</a>
      @else
        · Elige fechas para descargar el CSV
      @endif
    @endcan
  </p>

  @if ($porSocio->isNotEmpty())
  <h3>Por socio</h3>
  <table class="tabla">
    <tr><th>Socio</th><th class="n">Gastos</th><th class="n">Del negocio</th><th class="n">No del negocio</th><th class="n">Con dinero propio</th><th class="n">Total</th><th class="n">Le debemos hoy</th></tr>
    @foreach ($porSocio as $s)
      <tr><td><a href="{{ route('gastos.index', array_filter(['user_id' => $s->user_id, 'desde' => $f['desde'], 'hasta' => $f['hasta']])) }}">{{ $s->nombre ?? 'Sin socio' }}</a></td>
        <td class="n">{{ $s->gastos }}</td><td class="n">{{ dinero($s->negocio) }}</td><td class="n">{{ dinero($s->no_negocio) }}</td>
        <td class="n">{{ dinero($s->propio) }}</td><td class="n"><b>{{ dinero($s->total) }}</b></td>
        <td class="n">{{ isset($pendiente[$s->user_id]) ? dinero($pendiente[$s->user_id]) : '—' }}</td></tr>
    @endforeach
    @if ($porSocio->count() > 1)
      <tr><td><b>Total</b></td><td class="n">{{ $porSocio->sum('gastos') }}</td><td class="n">{{ dinero($porSocio->sum('negocio')) }}</td><td class="n">{{ dinero($porSocio->sum('no_negocio')) }}</td>
        <td class="n">{{ dinero($porSocio->sum('propio')) }}</td><td class="n"><b>{{ dinero($porSocio->sum('total')) }}</b></td><td></td></tr>
    @endif
  </table>
  <p class="ayuda">“Con dinero propio” es lo que el socio pagó de su bolsa en este periodo. “Le debemos hoy” es lo que falta reembolsarle en total, sin importar las fechas del filtro.</p>
  <h3>Detalle</h3>
  @endif
  <table class="tabla">
    <tr><th>Fecha</th><th>Categoría</th><th>Cuarto</th><th>Descripción</th><th>Pagó</th><th>Comprobante</th><th></th><th class="n">Monto</th><th></th></tr>
    @foreach ($gastos as $g)
      @php($puede = auth()->user()->can('modificar-gasto', $g))
      <tr class="{{ $g->personal ? 'personal' : '' }}">
        <td>{{ $g->fecha->toDateString() }}</td><td>{{ $g->categoria }}</td><td>{{ $g->personal ? '—' : ($g->cuarto->nombre ?? 'General') }}</td>
        <td>{{ $g->descripcion }}</td><td>{{ $g->user->name ?? '' }}@if ($g->capturado_por && $g->capturado_por !== $g->user_id)<div class="ayuda">capturó {{ $g->capturadoPor->name ?? '' }}</div>@endif</td>
        <td>
          @foreach ($g->comprobantes as $c)
            <a class="clip" href="{{ route('comprobantes.show', $c) }}" target="_blank" title="{{ $c->nombre_original }}">📎</a>
          @endforeach
          <form method="post" action="{{ route('comprobantes.store', $g) }}" enctype="multipart/form-data" class="inline">
            @csrf
            <label class="link-subir" title="Agregar comprobante">＋<input type="file" name="comprobantes[]" hidden accept="image/*,application/pdf,.xml" multiple data-autoenviar></label>
          </form>
        </td>
        <td>
          @if ($g->factura)<span class="tag">Factura</span>@endif
          @if ($g->personal)<span class="tag gris">No es del negocio</span>@endif
          @if ($g->cuenta)<span class="tag gris">{{ $g->cuenta->nombre }}</span>@else<span class="tag ambar">Por reembolsar</span>@endif
        </td>
        <td class="n">{{ dinero($g->monto) }}</td>
        <td>@if ($puede)<form method="post" action="{{ route('gastos.destroy', $g) }}" class="inline" data-confirmar="¿Borrar este gasto y sus comprobantes?">@csrf @method('delete')<button class="link">Borrar</button></form>@endif</td>
      </tr>
    @endforeach
  </table>
  <div class="paginacion">{{ $gastos->links('partials.paginacion') }}</div>
</div>
@push('scripts')
<script>
  // "No es del negocio" no se carga a ningún cuarto.
  const f = document.getElementById('formGasto');
  const sync = () => { f.cuarto_id.disabled = f.personal.checked; if (f.personal.checked) f.cuarto_id.value = ''; };
  f.personal.addEventListener('change', sync); sync();
  // Con factura, la factura es obligatoria.
  const archivo = f.querySelector('input[type=file]');
  const syncFactura = () => { archivo.required = f.factura.checked; };
  f.factura.addEventListener('change', syncFactura); syncFactura();
</script>
@endpush
@endsection
