<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Cobranza') · MiBait</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="topbar">
        <a href="{{ route('asignaciones.index') }}" class="brand">
            <span class="dot"></span> MiBait <small>Cobranza</small>
        </a>
        <nav class="nav">
            <a href="{{ route('asignaciones.index') }}" class="{{ request()->routeIs('asignaciones.index') ? 'active' : '' }}">Asignaciones</a>
            <a href="{{ route('metricas.index') }}" class="{{ request()->routeIs('metricas.index') ? 'active' : '' }}">Métricas</a>
            <a href="{{ route('recurrentes.index') }}" class="{{ request()->routeIs('recurrentes.index') ? 'active' : '' }}">Recurrentes</a>
            <a href="{{ route('asignaciones.create') }}" class="{{ request()->routeIs('asignaciones.create') ? 'active' : '' }}">Cargar cartera</a>
        </nav>
    </header>

    <main class="wrap">
        @if (session('error'))
            <div class="alert err">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
