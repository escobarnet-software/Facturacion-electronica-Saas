<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panel' }} · Escobar Net DTE</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
@php
    $tenancy = app(\App\Tenancy\Tenancy::class);
    $empresa = $tenancy->hayEmpresa() ? \App\Models\Empresa::query()->find($tenancy->empresaId()) : null;
    $usuario = auth()->user();
    $rol = $usuario?->rolActual();
    $misEmpresas = $usuario?->empresas()->orderBy('razon_social')->get() ?? collect();
    $menu = [
        'General' => [
            ['panel', 'inicio', 'Inicio', null],
        ],
        'Ventas' => [
            ['panel.emitir', 'emitir', 'Emitir documento', 'emitir'],
            ['panel.caja', 'caja', 'Caja rápida', 'emitir'],
        ],
        'Documentos' => [
            ['panel.documentos', 'documentos', 'Documentos emitidos', 'ver-documentos'],
            ['panel.invalidaciones', 'invalidar', 'Invalidaciones', 'invalidar'],
            ['panel.contingencia', 'alerta', 'Contingencia', 'ver-contingencia'],
        ],
        'Compras e impuestos' => [
            ['panel.recibidos', 'recibidos', 'Documentos recibidos', 'ver-libros'],
            ['panel.reportes', 'libros', 'Libros de IVA', 'ver-libros'],
        ],
        'Catálogos' => [
            ['panel.productos', 'productos', 'Productos y servicios', 'administrar-catalogos'],
            ['panel.clientes', 'clientes', 'Clientes', 'administrar-catalogos'],
            ['panel.establecimientos', 'sucursales', 'Sucursales y cajas', 'administrar-catalogos'],
        ],
        'Mi empresa' => [
            ['panel.onboarding', 'configuracion', 'Configuración y Hacienda', 'administrar-empresa'],
            ['panel.suscripcion', 'plan', 'Plan y soporte', 'administrar-empresa'],
            ['panel.api', 'api', 'API e integraciones', 'administrar-empresa'],
        ],
    ];
@endphp
<div x-data="{ abierto: false }" class="min-h-full">
    {{-- Fondo oscuro del menú en celular --}}
    <div x-show="abierto" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" x-on:click="abierto = false" x-cloak></div>

    {{-- Menú lateral --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-gradient-to-b from-marca-900 via-marca-800 to-marca-900 transition-transform lg:translate-x-0"
           x-bind:class="abierto ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
            <div class="flex size-9 items-center justify-center rounded-xl bg-white/15 text-white"><x-icono nombre="escudo" clase="size-5"/></div>
            <div class="leading-tight">
                <div class="font-bold text-white">Escobar Net</div>
                <div class="text-[11px] font-medium tracking-wide text-marca-200">Facturación electrónica</div>
            </div>
            <button type="button" class="ml-auto text-marca-100 lg:hidden" x-on:click="abierto = false" aria-label="Cerrar menú"><x-icono nombre="cerrar"/></button>
        </div>
        <nav class="menu-scroll flex-1 overflow-y-auto overscroll-contain px-3 pb-4" aria-label="Menú principal">
            @foreach ($menu as $grupo => $enlaces)
                @php($visibles = array_filter($enlaces, fn ($e) => $e[3] === null || \Illuminate\Support\Facades\Gate::allows($e[3])))
                @if ($visibles !== [])
                    <div class="nav-grupo">{{ $grupo }}</div>
                    @foreach ($visibles as [$ruta, $icono, $texto])
                        <a href="{{ route($ruta) }}" @class(['nav-enlace', 'nav-enlace-activo' => request()->routeIs($ruta)]) @if (request()->routeIs($ruta)) aria-current="page" @endif>
                            <x-icono :nombre="$icono" clase="size-5 shrink-0 opacity-90"/>{{ $texto }}
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>
    </aside>

    <div class="lg:pl-64">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
            <button type="button" class="text-slate-600 lg:hidden" x-on:click="abierto = true" aria-label="Abrir menú"><x-icono nombre="menu" clase="size-6"/></button>
            @if ($empresa)
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-tinta">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
                    <div class="truncate text-xs text-slate-500">NIT {{ $empresa->nit }}@if ($empresa->nrc) · NRC {{ $empresa->nrc }}@endif</div>
                </div>
                @if ($empresa->ambiente === '01')
                    <span class="insignia-verde hidden sm:inline-flex">Producción</span>
                @else
                    <span class="insignia-ambar hidden sm:inline-flex">Ambiente de pruebas</span>
                @endif
            @endif
            <div class="ml-auto flex items-center gap-3">
                @if ($misEmpresas->count() > 1)
                    <form method="POST" action="{{ route('empresa-activa') }}" class="hidden items-center gap-2 md:flex">
                        @csrf
                        <label for="empresa_id" class="sr-only">Cambiar de empresa</label>
                        <select id="empresa_id" name="empresa_id" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm" x-on:change="$el.form.submit()">
                            @foreach ($misEmpresas as $opcion)<option value="{{ $opcion->id }}" @selected($opcion->id === $empresa?->id)>{{ $opcion->razon_social }}</option>@endforeach
                        </select>
                        <noscript><button class="btn-secundario">Cambiar</button></noscript>
                    </form>
                @endif
                @if ($usuario)
                    <div class="hidden text-right sm:block">
                        <div class="text-sm font-medium text-tinta">{{ $usuario->nombre }}</div>
                        <div class="text-xs text-slate-500">{{ $rol?->etiqueta() ?? ($usuario->es_superadmin ? 'Soporte' : '') }}</div>
                    </div>
                    <div class="flex size-9 items-center justify-center rounded-full bg-marca-100 text-sm font-bold text-marca-700">{{ mb_strtoupper(mb_substr($usuario->nombre, 0, 1)) }}</div>
                @endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" title="Salir" aria-label="Salir"><x-icono nombre="salir"/></button></form>
            </div>
        </header>

        @if (session()->has(\App\Plataforma\Soporte::SESION))
            <div class="border-b border-amber-200 bg-amber-50 px-6 py-2 text-center text-sm text-amber-900" role="status">
                Modo soporte (solo consulta) con permiso temporal de la empresa.
                <form method="POST" action="{{ route('admin.soporte.salir') }}" class="inline">@csrf<button class="font-semibold underline">Salir del modo soporte</button></form>
            </div>
        @endif

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
