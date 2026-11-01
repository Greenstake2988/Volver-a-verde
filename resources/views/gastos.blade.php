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
    <label class="check"><input type="checkbox" name="factura" value="1" @checked(old('factura'))> Tiene factura</label>
    <label class="check"><input type="checkbox" name="personal" value="1" @checked(old('personal'))> No es del negocio</label>
    <label class="check"><input type="checkbox" name="de_la_cuenta" value="1" @checked(old('de_la_cuenta', true))> Pagado desde la cuenta del negocio</label>
    <label class="archivo">📎 Comprobante (foto, PDF o XML)
      <input type="file" name="comprobantes[]" accept="image/*,application/pdf,.xml" multiple>
    </label>
    <button>Guardar</button>
  </form>
  <p class="ayuda">“No es del negocio”: el gasto queda registrado y sale en el reporte para el contador, pero no se resta en la utilidad ni en la rentabilidad de los cuartos. Si además lo pagaste desde la cuenta del negocio, sí baja el saldo en el flujo de efectivo. Qué facturas se pueden deducir lo decide el contador.</p>
</div>

<div class="card">
  <form method="get" class="fila">
    <label>Mostrar
      <select name="filtro" onchange="this.form.submit()">
        @foreach (['todos' => 'Todos', 'mios' => 'Los que yo registré', 'negocio' => 'Solo del negocio', 'personal' => 'Solo los que no son del negocio', 'factura' => 'Solo con factura', 'sin-comprobante' => 'Sin comprobante'] as $v => $txt)
          <option value="{{ $v }}" @selected($filtro === $v)>{{ $txt }}</option>
        @endforeach
      </select>
    </label>
  </form>
  <table class="tabla">
    <tr><th>Fecha</th><th>Categoría</th><th>Cuarto</th><th>Descripción</th><th>Registró</th><th>Comprobante</th><th></th><th class="n">Monto</th><th></th></tr>
    @foreach ($gastos as $g)
      @php($puede = auth()->user()->can('modificar-gasto', $g))
      <tr class="{{ $g->personal ? 'personal' : '' }}">
        <td>{{ $g->fecha->toDateString() }}</td><td>{{ $g->categoria }}</td><td>{{ $g->personal ? '—' : ($g->cuarto->nombre ?? 'General') }}</td>
        <td>{{ $g->descripcion }}</td><td>{{ $g->user->name ?? '' }}</td>
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
          @unless ($g->de_la_cuenta)<span class="tag gris">Otra cuenta</span>@endunless
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
</script>
@endpush
@endsection
