@extends('layouts.app', ['titulo' => 'Ajustes'])
@section('contenido')
@can('admin')
<div class="card">
  <h2>Usuarios</h2>
  <p class="ayuda">Cada socio entra con su propio usuario. Los socios registran gastos, suben comprobantes y ven los reportes. Los administradores además importan, capturan reservas y cambian ajustes.</p>
  <table class="tabla">
    <tr><th>Nombre</th><th>Usuario</th><th>Tipo</th><th></th></tr>
    @foreach ($usuarios as $u)
      <tr><td>{{ $u->name }}</td><td>{{ $u->usuario }}</td><td>{{ $u->admin ? 'Administrador' : 'Socio' }}</td>
        <td>@unless ($u->is(auth()->user()))<form method="post" action="{{ route('ajustes.usuarios.destroy', $u) }}" class="inline" data-confirmar="¿Borrar a {{ $u->name }}? Sus gastos se conservan.">@csrf @method('delete')<button class="link">Borrar</button></form>@endunless</td></tr>
    @endforeach
  </table>
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
  <h2>Saldo de la cuenta</h2>
  <p class="ayuda">Pon el saldo que tenía la cuenta en una fecha. A partir de ahí la app suma depósitos y resta gastos.</p>
  <form method="post" action="{{ route('ajustes.saldo') }}" class="fila">
    @csrf
    <label>Saldo <input type="number" step="0.01" name="saldo_inicial" value="{{ $saldo }}" required></label>
    <label>al día <input type="date" name="fecha_saldo" value="{{ $fechaSaldo }}" required></label>
    <button>Guardar</button>
  </form>
</div>
<div class="card">
  <h2>Cuartos</h2>
  <p class="ayuda">“Anuncio en Airbnb” es el nombre del anuncio tal como aparece en el CSV; sirve para saber a qué cuarto pertenece cada reserva.</p>
  <form method="post" action="{{ route('ajustes.cuartos') }}">
    @csrf
    @foreach ($cuartos as $c)
      <div class="fila"><input name="cuartos[{{ $c->id }}][nombre]" value="{{ $c->nombre }}" required>
        <input name="cuartos[{{ $c->id }}][anuncio_airbnb]" value="{{ $c->anuncio_airbnb }}" placeholder="Anuncio en Airbnb"></div>
    @endforeach
    <button>Guardar cuartos</button>
  </form>
</div>
@endcan
@endsection
