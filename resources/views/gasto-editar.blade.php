@extends('layouts.app', ['titulo' => 'Editar gasto'])
@section('contenido')
<div class="card">
  <h2>Editar gasto</h2>
  <form method="post" action="{{ route('gastos.update', $g) }}" enctype="multipart/form-data" class="fila" id="formGasto">
    @csrf @method('put')
    <input type="hidden" name="volver" value="{{ request('volver', old('volver')) }}">
    <input type="date" name="fecha" value="{{ old('fecha', $g->fecha->toDateString()) }}" required>
    <input type="number" step="0.01" min="0.01" name="monto" placeholder="Monto" value="{{ old('monto', number_format($g->monto, 2, '.', '')) }}" required inputmode="decimal">
    <select name="categoria" required>
      @foreach (\App\Models\Gasto::CATEGORIAS as $c)<option @selected(old('categoria', $g->categoria) === $c)>{{ $c }}</option>@endforeach
    </select>
    <select name="cuarto_id"><option value="">General (toda la casa)</option>
      @foreach ($cuartos as $c)<option value="{{ $c->id }}" @selected(old('cuarto_id', $g->cuarto_id) == $c->id)>{{ $c->nombre }}{{ $c->activo ? '' : ' (desactivado)' }}</option>@endforeach
    </select>
    <input name="descripcion" placeholder="Descripción" value="{{ old('descripcion', $g->descripcion) }}">
    <label>¿Quién lo gastó?
      <select name="user_id">
        @foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected(old('user_id', $g->user_id) == $u->id)>{{ $u->name }}</option>@endforeach
      </select>
    </label>
    <label class="check"><input type="checkbox" name="factura" value="1" @checked(old('factura', $g->factura))> Tiene factura</label>
    <label class="check"><input type="checkbox" name="personal" value="1" @checked(old('personal', $g->personal))> No es del negocio</label>
    @php($pago = (string) old('cuenta_id', (string) $g->cuenta_id))
    <label>¿Con qué se pagó?
      <select name="cuenta_id">
        @foreach ($cuentas as $c)<option value="{{ $c->id }}" @selected($pago === (string) $c->id)>{{ $c->nombre }}</option>@endforeach
        <option value="" @selected($pago === '')>Dinero propio del socio (el negocio se lo reembolsa)</option>
      </select>
    </label>
    <label class="archivo">📎 Agregar comprobante
      <input type="file" name="comprobantes[]" accept="image/*,application/pdf,.xml" multiple>
    </label>
    <button>Guardar cambios</button>
    <a href="{{ request('volver') ?: route('gastos.index') }}">Cancelar</a>
  </form>
  @if ($g->comprobantes->isNotEmpty())
    <p class="ayuda">Comprobantes:
      @foreach ($g->comprobantes as $c)<a class="clip" href="{{ route('comprobantes.show', $c) }}" target="_blank">📎 {{ $c->nombre_original }}</a> @endforeach
    </p>
  @endif
</div>
@push('scripts')
<script>
  const f = document.getElementById('formGasto');
  const sync = () => { f.cuarto_id.disabled = f.personal.checked; if (f.personal.checked) f.cuarto_id.value = ''; };
  f.personal.addEventListener('change', sync); sync();
</script>
@endpush
@endsection
