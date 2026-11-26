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
    use Illuminate\Support\Facades\Gate;

    $tenancy = app(\App\Tenancy\Tenancy::class);
    $empresa = $tenancy->hayEmpresa() ? \App\Models\Empresa::query()->find($tenancy->empresaId()) : null;
    $usuario = auth()->user();
    $rol = $usuario?->rolActual();
    $misEmpresas = $usuario?->empresas()->orderBy('razon_social')->get() ?? collect();

    // Avisos en el menú: lo que requiere atención se ve sin entrar a cada pantalla.
    $rechazados = $empresa ? \App\Models\Dte::query()->where('estado', \App\Enums\EstadoDte::Rechazado->value)->count() : 0;
    $enContingencia = $empresa && \App\Models\Contingencia::query()->where('estado', \App\Models\Contingencia::ACTIVA)->exists();

    /*
    | Menú en el orden en que se trabaja: vender → revisar documentos → reportes → catálogos → configuración.
    | Cada enlace: [ruta, ícono, texto, permiso (null = todos), aviso opcional [texto, color]].
    */
    $menu = [
        ['titulo' => 'Escobar Net', 'enlaces' => [
            ['admin.empresas', 'sucursales', 'Empresas clientes', 'superadmin'],
            ['admin.planes', 'plan', 'Planes y precios', 'superadmin'],
            ['admin.cobros', 'dinero', 'Cobros', 'superadmin'],
            ['admin.estado', 'escudo', 'Estado de la plataforma', 'superadmin'],
            ['horizon.index', 'libros', 'Colas de trabajo', 'superadmin'],
        ]],
        ['titulo' => null, 'enlaces' => $empresa ? [
            ['panel', 'inicio', 'Inicio', null],
            ['panel.caja', 'caja', 'Caja rápida', 'emitir'],
        ] : []],
        ['titulo' => 'Mis documentos', 'enlaces' => [
            ['panel.documentos', 'documentos', 'Emitidos', 'ver-documentos', $rechazados > 0 ? [$rechazados.' rechazado'.($rechazados > 1 ? 's' : ''), 'rojo'] : null],
            ['panel.recibidos', 'recibidos', 'Recibidos de proveedores', 'ver-libros'],
            ['panel.invalidaciones', 'invalidar', 'Anular documentos', 'invalidar'],
            ['panel.contingencia', 'alerta', 'Contingencia', 'ver-contingencia', $enContingencia ? ['Activa', 'ambar'] : null],
        ]],
        ['titulo' => 'Reportes', 'enlaces' => [
            ['panel.reportes', 'libros', 'Libros de IVA', 'ver-libros'],
        ]],
        ['titulo' => 'Mi negocio', 'enlaces' => [
            ['panel.productos', 'productos', 'Productos y servicios', 'administrar-catalogos'],
            ['panel.clientes', 'clientes', 'Clientes', 'administrar-catalogos'],
            ['panel.establecimientos', 'sucursales', 'Sucursales y cajas', 'administrar-catalogos'],
        ]],
        ['titulo' => 'Configuración', 'enlaces' => [
            ['panel.onboarding', 'configuracion', 'Empresa y Hacienda', 'administrar-empresa'],
            ['panel.usuarios', 'usuarios', 'Usuarios y permisos', 'administrar-empresa'],
            ['panel.suscripcion', 'plan', 'Plan y soporte', 'administrar-empresa'],
            ['panel.api', 'api', 'API e integraciones', 'administrar-empresa'],
        ]],
    ];
    foreach ($menu as $i => $grupo) {
        $menu[$i]['enlaces'] = array_values(array_filter($grupo['enlaces'], fn ($e) => $e[3] === null || Gate::allows($e[3])));
        $menu[$i]['activo'] = collect($menu[$i]['enlaces'])->contains(fn ($e) => request()->routeIs($e[0]));
    }
    $puedeEmitir = $empresa && Gate::allows('emitir');
    $inicial = mb_strtoupper(mb_substr($usuario?->nombre ?? '?', 0, 1));
@endphp
<div x-data="{ abierto: false }" class="min-h-full">
    {{-- Fondo oscuro del menú en celular --}}
    <div x-show="abierto" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" x-on:click="abierto = false" x-cloak></div>

    {{-- Menú lateral --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-marca-950 transition-transform lg:translate-x-0"
           x-bind:class="abierto ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        <div class="flex h-16 shrink-0 items-center gap-3 px-5">
            <div class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-marca-400 to-marca-600 text-white shadow-lg shadow-marca-900/50"><x-icono nombre="escudo" clase="size-5"/></div>
            <div class="leading-tight">
                <div class="font-bold tracking-tight text-white">Escobar Net</div>
                <div class="text-[11px] font-medium text-marca-300">Facturación electrónica</div>
            </div>
            <button type="button" class="ml-auto rounded-lg p-1 text-marca-200 hover:bg-white/10 lg:hidden" x-on:click="abierto = false" aria-label="Cerrar menú"><x-icono nombre="cerrar"/></button>
        </div>

        @if ($puedeEmitir)
            <div class="px-3 pb-2">
                <a href="{{ route('panel.emitir') }}" @class(['flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold shadow-lg transition', 'bg-white text-marca-800 shadow-marca-950/40 hover:bg-marca-50' => ! request()->routeIs('panel.emitir'), 'bg-marca-500 text-white shadow-marca-950/40' => request()->routeIs('panel.emitir')])>
                    <x-icono nombre="mas" clase="size-4"/>Nuevo documento
                </a>
            </div>
        @endif

        <nav class="menu-scroll flex-1 overflow-y-auto overscroll-contain px-3 pb-4" aria-label="Menú principal">
            @foreach ($menu as $grupo)
                @continue($grupo['enlaces'] === [])
                @if (! empty($grupo['plegable']))
                    <div x-data="{ abiertoGrupo: {{ $grupo['activo'] ? 'true' : 'false' }} }" class="mt-1">
                        <button type="button" class="nav-grupo flex w-full items-center justify-between hover:text-marca-100" x-on:click="abiertoGrupo = ! abiertoGrupo" x-bind:aria-expanded="abiertoGrupo">
                            <span>{{ $grupo['titulo'] }}</span>
                            <span class="transition-transform" x-bind:class="abiertoGrupo ? 'rotate-180' : ''"><x-icono nombre="abajo" clase="size-3.5"/></span>
                        </button>
                        <div x-show="abiertoGrupo" @if (! $grupo['activo']) x-cloak @endif>
                            @include('layouts.partials.enlaces-menu', ['enlaces' => $grupo['enlaces']])
                        </div>
                    </div>
                @else
                    @if ($grupo['titulo'])<div class="nav-grupo">{{ $grupo['titulo'] }}</div>@else<div class="pt-2"></div>@endif
                    @include('layouts.partials.enlaces-menu', ['enlaces' => $grupo['enlaces']])
                @endif
            @endforeach
        </nav>

        {{-- Cuenta del usuario, siempre visible abajo del menú --}}
        @if ($usuario)
            <div class="shrink-0 border-t border-white/10 p-3">
                <a href="{{ route('panel.cuenta') }}" @class(['flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-white/10', 'bg-white/10' => request()->routeIs('panel.cuenta')])>
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-marca-500/30 text-sm font-bold text-white ring-1 ring-white/20">{{ $inicial }}</span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold text-white">{{ $usuario->nombre }}</span>
                        <span class="block truncate text-xs text-marca-300">{{ $rol?->etiqueta() ?? ($usuario->es_superadmin ? 'Superadmin' : '') }} · Mi cuenta</span>
                    </span>
                </a>
            </div>
        @endif
    </aside>

    <div class="lg:pl-64">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/85 px-4 backdrop-blur-md sm:px-6">
            <button type="button" class="-ml-1 rounded-lg p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden" x-on:click="abierto = true" aria-label="Abrir menú"><x-icono nombre="menu" clase="size-6"/></button>
            @if ($empresa)
                <div class="flex min-w-0 items-center gap-3">
                    <div class="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 sm:flex"><x-icono nombre="sucursales" clase="size-5"/></div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-tinta">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
                        <div class="truncate text-xs text-slate-500">NIT {{ $empresa->nit }}@if ($empresa->nrc) · NRC {{ $empresa->nrc }}@endif</div>
                    </div>
                </div>
                @if ($empresa->ambiente === '01')
                    <span class="insignia-verde hidden sm:inline-flex"><span class="size-1.5 rounded-full bg-emerald-500"></span>Producción</span>
                @else
                    <span class="insignia-ambar hidden sm:inline-flex" title="Los documentos de pruebas no tienen valor legal"><span class="size-1.5 rounded-full bg-amber-500"></span>Ambiente de pruebas</span>
                @endif
            @elseif ($usuario?->es_superadmin)
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-tinta">Administración de la plataforma</div>
                    <div class="truncate text-xs text-slate-500">Escobar Net · Superadmin</div>
                </div>
            @endif

            <div class="ml-auto flex items-center gap-2">
                @if ($misEmpresas->count() > 1)
                    <form method="POST" action="{{ route('empresa-activa') }}" class="hidden items-center gap-2 md:flex">
                        @csrf
                        <label for="empresa_id" class="sr-only">Cambiar de empresa</label>
                        <select id="empresa_id" name="empresa_id" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm" x-on:change="$el.form.submit()">
                            @foreach ($misEmpresas as $opcion)<option value="{{ $opcion->id }}" @selected($opcion->id === $empresa?->id)>{{ $opcion->razon_social }}</option>@endforeach
                        </select>
                        <noscript><button class="btn-secundario">Cambiar</button></noscript>
                    </form>
                @endif
                @if ($usuario)
                    <div class="relative" x-data="{ menuUsuario: false }" x-on:click.outside="menuUsuario = false" x-on:keydown.escape="menuUsuario = false">
                        <button type="button" class="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 hover:bg-slate-100" x-on:click="menuUsuario = ! menuUsuario" x-bind:aria-expanded="menuUsuario" aria-haspopup="true" aria-label="Menú de la cuenta">
                            <span class="flex size-8 items-center justify-center rounded-full bg-marca-100 text-sm font-bold text-marca-700">{{ $inicial }}</span>
                            <span class="hidden text-left leading-tight sm:block">
                                <span class="block text-sm font-medium text-tinta">{{ $usuario->nombre }}</span>
                                <span class="block text-xs text-slate-500">{{ $rol?->etiqueta() ?? ($usuario->es_superadmin ? 'Superadmin' : '') }}</span>
                            </span>
                            <x-icono nombre="abajo" clase="hidden size-4 text-slate-400 sm:block"/>
                        </button>
                        <div x-show="menuUsuario" x-transition x-cloak class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl shadow-slate-900/10">
                            <div class="border-b border-slate-100 px-4 py-2.5">
                                <div class="truncate text-sm font-semibold text-tinta">{{ $usuario->nombre }}</div>
                                <div class="truncate text-xs text-slate-500">{{ $usuario->email }}</div>
                            </div>
                            <a href="{{ route('panel.cuenta') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"><x-icono nombre="llave" clase="size-4 text-slate-400"/>Contraseña y seguridad</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50"><x-icono nombre="salir" clase="size-4"/>Cerrar sesión</button></form>
                        </div>
                    </div>
                @endif
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
