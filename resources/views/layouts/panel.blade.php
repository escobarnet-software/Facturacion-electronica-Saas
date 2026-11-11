<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panel' }} · Escobar Net DTE</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
@php($empresa = app(\App\Tenancy\Tenancy::class)->hayEmpresa() ? \App\Models\Empresa::query()->find(app(\App\Tenancy\Tenancy::class)->empresaId()) : null)
<header class="border-b bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-3">
        <a href="{{ route('panel') }}" class="font-semibold text-blue-800">Escobar Net DTE</a>
        @if ($empresa)
            <span class="text-sm text-slate-500">{{ $empresa->nombre_comercial ?? $empresa->razon_social }} · NIT {{ $empresa->nit }} · {{ $empresa->ambiente === '01' ? 'Producción' : 'Pruebas' }}</span>
        @endif
        @php($misEmpresas = auth()->user()?->empresas()->orderBy('razon_social')->get() ?? collect())
        @if ($misEmpresas->count() > 1)
            <form method="POST" action="{{ route('empresa-activa') }}" class="flex items-center gap-1 text-sm">
                @csrf
                <label for="empresa_id" class="sr-only">Cambiar de empresa</label>
                <select id="empresa_id" name="empresa_id" class="rounded border p-1">
                    @foreach ($misEmpresas as $opcion)<option value="{{ $opcion->id }}" @selected($opcion->id === $empresa?->id)>{{ $opcion->razon_social }}</option>@endforeach
                </select>
                <button class="rounded border px-2">Cambiar</button>
            </form>
        @endif
        <nav class="flex flex-wrap gap-3 text-sm">
            <a href="{{ route('panel') }}" class="hover:underline">Inicio</a>
            @can('emitir')<a href="{{ route('panel.emitir') }}" class="hover:underline">Emitir</a>
            <a href="{{ route('panel.caja') }}" class="hover:underline">Caja rápida</a>@endcan
            @can('ver-documentos')<a href="{{ route('panel.documentos') }}" class="hover:underline">Documentos</a>@endcan
            @can('invalidar')<a href="{{ route('panel.invalidaciones') }}" class="hover:underline">Invalidaciones</a>@endcan
            @can('ver-contingencia')<a href="{{ route('panel.contingencia') }}" class="hover:underline">Contingencia</a>@endcan
            @can('administrar-catalogos')
                <a href="{{ route('panel.productos') }}" class="hover:underline">Productos</a>
                <a href="{{ route('panel.clientes') }}" class="hover:underline">Clientes</a>
                <a href="{{ route('panel.establecimientos') }}" class="hover:underline">Establecimientos</a>
            @endcan
            @can('administrar-empresa')<a href="{{ route('panel.onboarding') }}" class="hover:underline">Configuración</a>@endcan
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="ml-auto">@csrf<button class="text-sm text-slate-500 hover:underline">Salir</button></form>
    </div>
</header>
<main class="mx-auto max-w-7xl px-4 py-6">
    {{ $slot }}
</main>
</body>
</html>
