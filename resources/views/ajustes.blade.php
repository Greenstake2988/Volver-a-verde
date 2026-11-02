@extends('layouts.app', ['titulo' => 'Ajustes'])
@section('contenido')
@can('admin')
<div class="card">
  <h2>Usuarios</h2>
  <p class="ayuda">Cada socio entra con su propio usuario. Los socios registran gastos, suben comprobantes y ven los reportes. Los administradores además importan, capturan reservas y cambian ajustes.</p>
  <p class="ayuda">“Tope al mes”: lo máximo que cada socio debería cargar al mes en gastos que no son del negocio con tarjetas o cuentas del negocio. Vacío = sin tope.</p>
  <form method="post" action="{{ route('ajustes.limites') }}" id="formLimites">@csrf</form>
  <table class="tabla">
    <tr><th>Nombre</th><th>Usuario</th><th>Tipo</th><th>Tope al mes</th><th></th></tr>
    @foreach ($usuarios as $u)
      <tr><td>{{ $u->name }}</td><td>{{ $u->usuario }}</td><td>{{ $u->admin ? 'Administrador' : 'Socio' }}</td>
        <td><input type="number" step="0.01" min="0" name="limites[{{ $u->id }}]" value="{{ $u->limite_mensual }}" placeholder="Sin tope" form="formLimites" style="width:120px"></td>
        <td>@unless ($u->is(auth()->user()))<form method="post" action="{{ route('ajustes.usuarios.destroy', $u) }}" class="inline" data-confirmar="¿Borrar a {{ $u->name }}? Sus gastos se conservan.">@csrf @method('delete')<button class="link">Borrar</button></form>@endunless</td></tr>
    @endforeach
  </table>
  <button form="formLimites" style="margin-top:10px">Guardar topes</button>
  <form method="post" action="{{ route('ajustes.usuarios') }}" class="fila" style="margin-top:12px">
    @csrf
    <input name="name" placeholder="Nombre" required>
    <input name="usuario" placeholder="Usuario" autocapitalize="none" required>
    <input name="password" type="text" placeholder="Contraseña (8+)" required minlength="8">
    <label class="check"><input type="checkbox" name="admin" value="1"> Administrador</label>
    <button>Agregar usuario</button>
  </form>
</div>
@endcan

<div class="card">
  <h2>Mi contraseña</h2>
  <form method="post" action="{{ route('ajustes.password') }}" class="fila">
    @csrf
    <input type="password" name="actual" placeholder="Contraseña actual" required>
    <input type="password" name="password" placeholder="Nueva (8+)" required minlength="8">
    <input type="password" name="password_confirmation" placeholder="Repite la nueva" required minlength="8">
    <button>Cambiar</button>
  </form>
</div>

@can('admin')
<div class="card">
  <h2>Cuentas</h2>
  <p class="ayuda">Pon el saldo que tenía cada cuenta en una fecha; a partir de ahí la app suma y resta. En las tarjetas de crédito pon lo que debías ese día.</p>
  <form method="post" action="{{ route('ajustes.cuentas') }}">
    @csrf
    <table class="tabla">
      <tr><th>Nombre</th><th>Tipo</th><th>La usa</th><th>Saldo / deuda inicial</th><th>Al día</th><th>Recibe Airbnb</th></tr>
      @foreach ($cuentas as $c)
        <tr>
          <td><input name="cuentas[{{ $c->id }}][nombre]" value="{{ $c->nombre }}" required></td>
          <td>{{ \App\Models\Cuenta::TIPOS[$c->tipo] ?? $c->tipo }}</td>
          <td><select name="cuentas[{{ $c->id }}][user_id]"><option value="">Nadie en especial</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected($c->user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></td>
          <td><input type="number" step="0.01" name="cuentas[{{ $c->id }}][saldo]" value="{{ $c->esCredito() ? abs($c->saldo_inicial) : $c->saldo_inicial }}" required></td>
          <td><input type="date" name="cuentas[{{ $c->id }}][fecha_saldo]" value="{{ $c->fecha_saldo->toDateString() }}" required></td>
          <td>@unless ($c->esCredito())<input type="radio" name="recibe_airbnb" value="{{ $c->id }}" @checked($c->recibe_airbnb)>@endunless</td>
        </tr>
      @endforeach
    </table>
    <button style="margin-top:10px">Guardar cuentas</button>
  </form>
  <form method="post" action="{{ route('ajustes.cuentas.store') }}" class="fila" style="margin-top:14px">
    @csrf
    <input name="nombre" placeholder="Nueva cuenta" required>
    <select name="tipo">@foreach (\App\Models\Cuenta::TIPOS as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select>
    <select name="user_id"><option value="">La usa: nadie en especial</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}">La usa: {{ $u->name }}</option>@endforeach</select>
    <input type="number" step="0.01" name="saldo" placeholder="Saldo / deuda" required>
    <input type="date" name="fecha_saldo" value="{{ now()->toDateString() }}" required>
    <button>Agregar cuenta</button>
  </form>
</div>
<div class="card">
  <h2>Cuartos</h2>
  <p class="ayuda">Al importar el CSV de Airbnb se crea un cuarto por cada anuncio nuevo, y después puedes cambiarle el nombre: al volver a importar se sigue reconociendo por su “Anuncio en Airbnb”. Si prefieres crear los cuartos a mano antes de importar, pon en “Anuncio en Airbnb” el principio del nombre del anuncio, por ejemplo “Volver a Verde 4”. Un cuarto que ya no se renta se desactiva: deja de contar para la ocupación y los gastos generales, pero conserva su historial. Solo se puede borrar un cuarto sin reservas ni gastos.</p>
  @if ($cuartos->isNotEmpty())
  <form method="post" action="{{ route('ajustes.cuartos') }}">
    @csrf
    @foreach ($cuartos as $c)
      <div class="fila"><input name="cuartos[{{ $c->id }}][nombre]" value="{{ $c->nombre }}" required>
        <input name="cuartos[{{ $c->id }}][anuncio_airbnb]" value="{{ $c->anuncio_airbnb }}" placeholder="Anuncio en Airbnb">
        <label class="check"><input type="hidden" name="cuartos[{{ $c->id }}][activo]" value="0"><input type="checkbox" name="cuartos[{{ $c->id }}][activo]" value="1" @checked($c->activo)> Activo</label>
        <button class="link" form="borrar-cuarto-{{ $c->id }}">Borrar</button></div>
    @endforeach
    <button>Guardar cuartos</button>
  </form>
  @foreach ($cuartos as $c)
    <form method="post" action="{{ route('ajustes.cuartos.destroy', $c) }}" id="borrar-cuarto-{{ $c->id }}" class="inline" data-confirmar="¿Borrar {{ $c->nombre }}?">@csrf @method('delete')</form>
  @endforeach
  @endif
  <h3>Agregar cuarto</h3>
  <form method="post" action="{{ route('ajustes.cuartos.store') }}" class="fila">
    @csrf
    <input name="nombre" placeholder="Nombre" required>
    <input name="anuncio_airbnb" placeholder="Anuncio en Airbnb, ej. Volver a Verde 4">
    <button>Agregar cuarto</button>
  </form>
</div>
@endcan
@endsection
