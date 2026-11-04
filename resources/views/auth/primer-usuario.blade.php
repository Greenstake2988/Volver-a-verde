<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Primer uso · Volver a verde</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login-pagina">
  <form method="post" action="{{ url('/primer-usuario') }}" class="card">
    @csrf
    <h1>Volver a verde</h1>
    <p class="ayuda">Primera vez: crea tu usuario de administrador. Después das de alta a los socios en Ajustes.</p>
    <label class="bloque">Tu nombre <input name="name" value="{{ old('name') }}" required autofocus></label>
    <label class="bloque">Usuario <input name="usuario" value="{{ old('usuario') }}" autocapitalize="none" required></label>
    <label class="bloque">Contraseña (12 o más, con mayúsculas, minúsculas y números) <input type="password" name="password" required minlength="12"></label>
    <label class="bloque">Repite la contraseña <input type="password" name="password_confirmation" required minlength="12"></label>
    <button>Crear y entrar</button>
    @if ($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
  </form>
</div>
</body>
</html>
