<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Superadmin' }} · Escobar Net DTE</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<header class="border-b bg-slate-900 text-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-3">
        <a href="{{ route('admin.empresas') }}" class="font-semibold">Escobar Net · Superadmin</a>
        <nav class="flex flex-wrap gap-3 text-sm">
            <a href="{{ route('admin.empresas') }}" class="hover:underline">Empresas</a>
            <a href="{{ route('admin.estado') }}" class="hover:underline">Estado</a>
            <a href="{{ route('admin.planes') }}" class="hover:underline">Planes</a>
            <a href="{{ route('admin.cobros') }}" class="hover:underline">Cobros</a>
            <a href="{{ url('/horizon') }}" class="hover:underline">Colas</a>
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="ml-auto">@csrf<button class="text-sm hover:underline">Salir</button></form>
    </div>
</header>
<main class="mx-auto max-w-7xl px-4 py-6">
    {{ $slot }}
</main>
</body>
</html>
