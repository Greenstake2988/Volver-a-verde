@extends('layouts.app', ['titulo' => 'Estados de cuenta'])
@section('contenido')
<div class="card">
  <h2>Subir estado de cuenta</h2>
  <p class="ayuda">Sube el PDF (o foto, CSV o Excel) del estado de cuenta de cada tarjeta, uno por mes. Puedes subir varios archivos del mismo mes.</p>
  @if ($puedeSubir->isEmpty())
    <p class="ayuda">No tienes tarjetas asignadas. Pídele al administrador que en Ajustes ponga que tú usas la tarjeta.</p>
  @else
    <form method="post" action="{{ route('estados.store') }}" enctype="multipart/form-data" class="fila">
      @csrf
      <select name="cuenta_id" required>
        @foreach ($puedeSubir as $c)
          <option value="{{ $c->id }}" @selected(old('cuenta_id', $puedeSubir->firstWhere('user_id', auth()->id())?->id) == $c->id)>{{ $c->nombre }}{{ $c->usuario ? ' · '.$c->usuario->name : '' }}</option>
        @endforeach
      </select>
      <input type="month" name="mes" value="{{ old('mes', now()->subMonthNoOverflow()->format('Y-m')) }}" max="{{ $mesActual }}" required>
      <input type="file" name="archivos[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.csv,.txt,.xls,.xlsx,.xml">
      <button>Subir</button>
    </form>
  @endif
</div>

<div class="card">
  <div class="fila" style="justify-content:space-between">
    <h2 style="margin:0">{{ $anio }}</h2>
    <span>
      <a href="{{ route('estados.index', ['anio' => $anio - 1]) }}">← {{ $anio - 1 }}</a>
      @if ($anio < now()->year) · <a href="{{ route('estados.index', ['anio' => $anio + 1]) }}">{{ $anio + 1 }} →</a>@endif
    </span>
  </div>
  <div style="overflow-x:auto">
  <table class="tabla">
    <tr><th>Tarjeta / cuenta</th>@foreach ($meses as $i => $m)<th>{{ $nombresMes[$i] }}</th>@endforeach</tr>
    @foreach ($cuentas as $c)
      <tr>
        <td class="nw">{{ $c->nombre }}@if ($c->usuario)<br><span class="ayuda">{{ $c->usuario->name }}</span>@endif</td>
        @foreach ($meses as $m)
          <td class="nw">
            @forelse ($estados->get($c->id.'|'.$m, collect()) as $e)
              <a href="{{ route('estados.show', $e) }}" target="_blank" title="{{ $e->nombre_original }} · subió {{ $e->user?->name ?? '—' }}">Ver</a>@if (auth()->user()->admin || $e->user_id === auth()->id())<form method="post" action="{{ route('estados.destroy', $e) }}" class="inline" data-confirmar="¿Borrar {{ $e->nombre_original }}?">@csrf @method('delete')<button class="link" title="Borrar">×</button></form>@endif<br>
            @empty
              @if ($m < $mesActual)<span class="tag gris">falta</span>@endif
            @endforelse
          </td>
        @endforeach
      </tr>
    @endforeach
  </table>
  </div>
</div>
@endsection
