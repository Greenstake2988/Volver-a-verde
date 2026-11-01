<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · Volver a verde</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login-pagina">
  <form method="post" action="{{ url('/login') }}" class="card">
    @csrf
    <h1>Volver a verde</h1>
    <p class="ayuda">Entra con tu usuario.</p>
    <label class="bloque">Usuario <input name="usuario" value="{{ old('usuario') }}" autocomplete="username" autocapitalize="none" required autofocus></label>
    <label class="bloque">Contraseña <input type="password" name="password" autocomplete="current-password" required></label>
    <button>Entrar</button>
    @if ($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
  </form>
</div>
</body>
</html>
