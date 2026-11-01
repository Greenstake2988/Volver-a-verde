<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo ?? 'Volver a verde' }} · Volver a verde</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="{{ auth()->user()->admin ? '' : 'socio' }}">
<header>
  <div class="barra-sup">
    <h1>Volver a verde</h1>
    <span id="quien">{{ auth()->user()->name }}{{ auth()->user()->admin ? ' · admin' : '' }}</span>
    <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="link claro">Salir</button></form>
  </div>
  <nav>
    @php($nav = ['resumen' => 'Resumen', 'flujo' => 'Flujo de efectivo', 'reservas.index' => 'Reservas', 'gastos.index' => 'Gastos'])
    @foreach ($nav as $ruta => $texto)
      <a href="{{ route($ruta) }}" class="{{ request()->routeIs($ruta) ? 'activo' : '' }}">{{ $texto }}</a>
    @endforeach
    @can('admin')
      <a href="{{ route('datos') }}" class="{{ request()->routeIs('datos') ? 'activo' : '' }}">Importar / Contador</a>
    @endcan
    <a href="{{ route('ajustes') }}" class="{{ request()->routeIs('ajustes') ? 'activo' : '' }}">Ajustes</a>
  </nav>
</header>
<main>
  @if (session('ok'))<div class="aviso ok">{{ session('ok') }}</div>@endif
  @if ($errors->any())
    <div class="aviso error"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif
  @yield('contenido')
</main>
<script>
  // Confirmación antes de borrar.
  document.addEventListener('submit', (e) => {
    if (e.target.matches('[data-confirmar]') && !confirm(e.target.dataset.confirmar)) e.preventDefault();
  });
  // Subir comprobante en cuanto se elige el archivo.
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-autoenviar]')) e.target.form.submit();
  });
</script>
@stack('scripts')
</body>
</html>
