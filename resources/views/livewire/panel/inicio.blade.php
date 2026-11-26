@php
    $listos = collect($flujo)->filter(fn ($p) => $p[2])->count();
    $configurado = $listos >= 3;
    $siguiente = collect($flujo)->search(fn ($p) => ! $p[2]);   // solo el próximo paso pendiente lleva botón   // empresa, catálogo y primer documento: ya sabe usarlo
    $acciones = collect([
        ['panel.emitir', ['tipo' => '01'], 'emitir', 'Factura', 'Venta a consumidor final', 'bg-marca-50 text-marca-600', 'emitir'],
        ['panel.emitir', ['tipo' => '03'], 'nota', 'Crédito Fiscal', 'Venta a contribuyente con NRC', 'bg-violet-50 text-violet-600', 'emitir'],
        ['panel.caja', [], 'caja', 'Caja rápida', 'Cobro en mostrador', 'bg-emerald-50 text-emerald-600', 'emitir'],
        ['panel.documentos', [], 'documentos', 'Mis documentos', 'Buscar, reenviar, anular', 'bg-sky-50 text-sky-600', 'ver-documentos'],
        ['panel.reportes', [], 'libros', 'Libros de IVA', 'Excel para el contador', 'bg-amber-50 text-amber-600', 'ver-libros'],
        ['panel.clientes', [], 'clientes', 'Clientes', 'Agregar o editar', 'bg-rose-50 text-rose-600', 'administrar-catalogos'],
    ])->filter(fn ($a) => \Illuminate\Support\Facades\Gate::allows($a[6]))->take(4);
    // Montos con separador de miles, sin pasar por float (regla del proyecto).
    $dinero = function ($v): string {
        $texto = \App\Support\Decimal::of((string) ($v ?? '0'))->toFixed(2);
        $signo = str_starts_with($texto, '-') ? '-' : '';
        [$entero, $decimales] = explode('.', ltrim($texto, '-'));

        return $signo.'$'.strrev(implode(',', str_split(strrev($entero), 3))).'.'.$decimales;
    };
@endphp
<div class="space-y-8">
    {{-- Saludo --}}
    <div class="encabezado">
        <div>
            <p class="!mt-0 text-xs font-semibold uppercase tracking-wider text-marca-600">{{ ucfirst(now('America/El_Salvador')->translatedFormat('l j \d\e F')) }}</p>
            <h1 class="mt-1">Hola, {{ \Illuminate\Support\Str::before(auth()->user()?->nombre ?? '', ' ') ?: auth()->user()?->nombre }}</h1>
            <p>{{ $configurado || ! \Illuminate\Support\Facades\Gate::any(['administrar-empresa', 'administrar-catalogos']) ? 'Este es el resumen de su negocio.' : 'Complete los primeros pasos y empiece a facturar.' }}</p>
        </div>
        @can('emitir')
            <a href="{{ route('panel.emitir') }}" class="btn-primario btn-grande"><x-icono nombre="mas" clase="size-5"/>Nuevo documento</a>
        @endcan
    </div>

    {{-- Lo que necesita atención --}}
    @if ($rechazados > 0 || $contingencia || $credencialesBloqueadas || $invalidacionesPorVencer > 0)
        <div class="space-y-2">
            @if ($rechazados > 0)
                <div class="alerta-error"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span class="flex-1">Hacienda rechazó <strong>{{ $rechazados }}</strong> documento(s). Corríjalos y reenvíelos.</span><a class="shrink-0 font-semibold underline" href="{{ route('panel.documentos', ['estado' => 'rechazado']) }}">Revisar</a></div>
            @endif
            @if ($contingencia)
                <div class="alerta-aviso"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span><strong>Hacienda no responde (contingencia).</strong> Puede seguir vendiendo: los documentos se entregan al cliente y se envían solos cuando vuelva el servicio.</span></div>
            @endif
            @if ($credencialesBloqueadas)
                <div class="alerta-error"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span class="flex-1">Las credenciales de Hacienda fallaron y se dejaron de usar.</span>@can('administrar-empresa')<a class="shrink-0 font-semibold underline" href="{{ route('panel.onboarding') }}">Cargarlas de nuevo</a>@endcan</div>
            @endif
            @if ($invalidacionesPorVencer > 0)
                <div class="alerta-aviso"><x-icono nombre="reloj" clase="size-5 shrink-0"/><span class="flex-1">{{ $invalidacionesPorVencer }} documento(s) solo se pueden anular en los próximos 3 días.</span>@can('invalidar')<a class="shrink-0 font-semibold underline" href="{{ route('panel.invalidaciones') }}">Ver</a>@endcan</div>
            @endif
        </div>
    @endif

    {{-- Primeros pasos: solo mientras falte algo --}}
    @if (! $configurado && \Illuminate\Support\Facades\Gate::any(['administrar-empresa', 'administrar-catalogos']))
        <section class="card overflow-hidden !p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-gradient-to-r from-marca-600 to-marca-500 px-6 py-5 text-white">
                <div>
                    <h2 class="!text-white">Primeros pasos</h2>
                    <p class="text-sm text-marca-100">Siga estos pasos en orden. Cada uno se marca solo cuando está listo.</p>
                </div>
                <div class="w-48">
                    <div class="mb-1 flex justify-between text-xs font-semibold"><span>Avance</span><span>{{ $listos }} de {{ count($flujo) }}</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-white/25"><div class="h-full rounded-full bg-white" style="width: {{ (int) round($listos / count($flujo) * 100) }}%"></div></div>
                </div>
            </div>
            <ol class="divide-y divide-slate-100">
                @foreach ($flujo as $i => [$titulo, $texto, $hecho, $ruta, $permiso])
                    <li class="flex items-center gap-4 px-6 py-4">
                        @if ($hecho)
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><x-icono nombre="ok" clase="size-5"/></span>
                        @else
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-marca-200 text-sm font-bold text-marca-700">{{ $i + 1 }}</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div @class(['text-sm font-semibold', 'text-slate-400 line-through decoration-slate-300' => $hecho, 'text-tinta' => ! $hecho])>{{ $titulo }}</div>
                            <div class="text-xs text-slate-500">{{ $texto }}</div>
                        </div>
                        @if ($i === $siguiente)
                            @can($permiso)<a href="{{ route($ruta) }}" class="btn-primario shrink-0">Empezar <x-icono nombre="derecha" clase="size-4"/></a>@endcan
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Acciones rápidas --}}
    @if ($acciones->isNotEmpty())
        <section>
            <h2 class="mb-3">¿Qué desea hacer?</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($acciones as [$ruta, $parametros, $icono, $titulo, $texto, $color])
                    <a href="{{ route($ruta, $parametros) }}" class="accion-rapida group">
                        <span class="accion-icono {{ $color }}"><x-icono :nombre="$icono" clase="size-6"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-tinta">{{ $titulo }}</span>
                            <span class="block text-xs text-slate-500">{{ $texto }}</span>
                        </span>
                        <x-icono nombre="derecha" clase="size-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-marca-500"/>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Números del negocio --}}
    <section>
        <h2 class="mb-3">Resumen</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            @foreach ([
                ['Ventas de hoy', $dinero($ventasHoy), 'IVA incluido', 'dinero', 'bg-emerald-50 text-emerald-600'],
                ['Documentos de hoy', $documentosHoy, 'Sin contar anulados', 'documentos', 'bg-marca-50 text-marca-600'],
                ['Ventas del mes', $dinero($ventasMes), ucfirst(now('America/El_Salvador')->translatedFormat('F Y')), 'libros', 'bg-sky-50 text-sky-600'],
                ['IVA del mes', $dinero($ivaMes), 'Débito fiscal a declarar', 'plan', 'bg-amber-50 text-amber-600'],
            ] as [$etiqueta, $valor, $nota, $icono, $color])
                <div class="card flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="kpi-etiqueta">{{ $etiqueta }}</div>
                        <div class="kpi-valor">{{ $valor }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $nota }}</div>
                    </div>
                    <span class="accion-icono hidden !size-10 sm:flex {{ $color }}"><x-icono :nombre="$icono" clase="size-5"/></span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Actividad reciente --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden !p-0 lg:col-span-2">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2>Últimos documentos</h2>
                @can('ver-documentos')<a href="{{ route('panel.documentos') }}" class="text-sm font-semibold text-marca-700 hover:underline">Ver todos</a>@endcan
            </div>
            <div class="overflow-x-auto"><table>
                <thead><tr><th>Documento</th><th>Cliente</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                @forelse ($ultimos as $d)
                    <tr>
                        <td><div class="text-sm font-medium text-tinta">{{ match ($d->tipo) { '01' => 'Factura', '03' => 'Crédito Fiscal', '05' => 'Nota de crédito', '06' => 'Nota de débito', default => 'Documento '.$d->tipo } }}</div><div class="font-mono text-[11px] text-slate-400">{{ $d->numero_control }}</div></td>
                        <td>{{ $d->receptor_nombre ?? 'Consumidor final' }}</td>
                        <td class="text-right font-semibold text-tinta">{{ $dinero($d->getRawOriginal('total_pagar')) }}</td>
                        <td><x-estado :estado="$d->estado"/></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-10 text-center">
                        <div class="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><x-icono nombre="documentos" clase="size-6"/></div>
                        <div class="text-sm font-medium text-slate-600">Aún no hay documentos</div>
                        @can('emitir')<a href="{{ route('panel.emitir') }}" class="mt-1 inline-block text-sm font-semibold text-marca-700 hover:underline">Emitir el primero</a>@endcan
                    </td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <h2 class="mb-4">Documentos por estado</h2>
            <ul class="space-y-3">
                @forelse ($porEstado as $estado => $n)
                    <li class="flex items-center justify-between"><x-estado :estado="$estado"/><span class="text-sm font-semibold text-tinta">{{ $n }}</span></li>
                @empty
                    <li class="text-sm text-slate-400">Cuando emita documentos, aquí verá cuántos están sellados, en espera o rechazados.</li>
                @endforelse
            </ul>
            @if ($configurado)
                <div class="mt-6 rounded-xl bg-slate-50 p-4 text-xs text-slate-600">
                    <div class="mb-1 flex items-center gap-1.5 font-semibold text-tinta"><x-icono nombre="ok" clase="size-4 text-emerald-600"/>Su empresa está configurada</div>
                    La plataforma firma, envía a Hacienda y entrega cada documento por correo de forma automática.
                </div>
            @endif
        </div>
    </div>
</div>
