<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1>Hola, {{ auth()->user()?->nombre }}</h1>
            <p class="mt-1 text-sm text-slate-500">Resumen de hoy, {{ now('America/El_Salvador')->translatedFormat('l j \d\e F \d\e Y') }}.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('emitir')
                <a href="{{ route('panel.caja') }}" class="btn-secundario"><x-icono nombre="caja" clase="size-4"/>Caja rápida</a>
                <a href="{{ route('panel.emitir') }}" class="btn-primario"><x-icono nombre="mas" clase="size-4"/>Emitir documento</a>
            @endcan
        </div>
    </div>

    @if ($rechazados > 0 || $contingencia || $credencialesBloqueadas || $invalidacionesPorVencer > 0)
        <div class="space-y-2">
            @if ($rechazados > 0)
                <div class="alerta-error"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span>Hay <strong>{{ $rechazados }}</strong> documento(s) rechazado(s) por Hacienda: corríjalos y reenvíelos desde <a class="underline" href="{{ route('panel.documentos', ['estado' => 'rechazado']) }}">Documentos</a>.</span></div>
            @endif
            @if ($contingencia)
                <div class="alerta-aviso"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span><strong>Contingencia activa:</strong> Hacienda no responde. Los documentos se entregan igual y se transmitirán solos al volver el servicio.</span></div>
            @endif
            @if ($credencialesBloqueadas)
                <div class="alerta-error"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span>Las credenciales de Hacienda fallaron y se dejaron de usar. Cárguelas de nuevo en Configuración.</span></div>
            @endif
            @if ($invalidacionesPorVencer > 0)
                <div class="alerta-aviso"><x-icono nombre="alerta" clase="size-5 shrink-0"/><span>{{ $invalidacionesPorVencer }} documento(s) con plazo de invalidación por vencer en los próximos 3 días.</span></div>
            @endif
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card"><div class="kpi-etiqueta">Ventas de hoy</div><div class="kpi-valor">${{ \App\Support\Decimal::of((string) $ventasHoy)->toFixed(2) }}</div><div class="mt-1 text-xs text-slate-500">IVA incluido</div></div>
        <div class="card"><div class="kpi-etiqueta">Documentos de hoy</div><div class="kpi-valor">{{ $documentosHoy }}</div><div class="mt-1 text-xs text-slate-500">Vigentes (sin invalidados)</div></div>
        <div class="card"><div class="kpi-etiqueta">Ventas del mes</div><div class="kpi-valor">${{ \App\Support\Decimal::of((string) $ventasMes)->toFixed(2) }}</div><div class="mt-1 text-xs text-slate-500">{{ now('America/El_Salvador')->translatedFormat('F Y') }}</div></div>
        <div class="card"><div class="kpi-etiqueta">IVA del mes</div><div class="kpi-valor">${{ \App\Support\Decimal::of((string) $ivaMes)->toFixed(2) }}</div><div class="mt-1 text-xs text-slate-500">Débito fiscal emitido</div></div>
    </div>

    <div class="card">
        <div class="card-titulo">Cómo funciona</div>
        <ol class="grid gap-4 md:grid-cols-5">
            @foreach ($flujo as $i => [$titulo, $texto, $hecho, $ruta, $permiso])
                <li class="relative rounded-xl border p-4 {{ $hecho ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200 bg-slate-50/60' }}">
                    <div class="flex items-center gap-2">
                        @if ($hecho)
                            <span class="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-white"><x-icono nombre="ok" clase="size-4"/></span>
                        @else
                            <span class="paso">{{ $i + 1 }}</span>
                        @endif
                        <span class="text-sm font-semibold text-tinta">{{ $titulo }}</span>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-slate-600">{{ $texto }}</p>
                    @can($permiso)<a href="{{ route($ruta) }}" class="mt-2 inline-block text-xs font-semibold text-marca-700 hover:underline">{{ $hecho ? 'Ver' : 'Ir ahora' }} →</a>@endcan
                </li>
            @endforeach
        </ol>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden !p-0 lg:col-span-2">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <div class="card-titulo !mb-0">Últimos documentos</div>
                @can('ver-documentos')<a href="{{ route('panel.documentos') }}" class="text-sm font-semibold text-marca-700 hover:underline">Ver todos</a>@endcan
            </div>
            <div class="overflow-x-auto"><table>
                <thead><tr><th>Número de control</th><th>Cliente</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                @forelse ($ultimos as $d)
                    <tr>
                        <td class="font-mono text-xs">{{ $d->numero_control }}</td>
                        <td>{{ $d->receptor_nombre ?? 'Consumidor final' }}</td>
                        <td class="text-right font-medium">${{ \App\Support\Decimal::of((string) $d->getRawOriginal('total_pagar'))->toFixed(2) }}</td>
                        <td><x-estado :estado="$d->estado"/></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-8 text-center text-slate-400">Aún no hay documentos. Empiece por «Emitir documento».</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <div class="card-titulo">Documentos por estado</div>
            <ul class="space-y-2">
                @forelse ($porEstado as $estado => $n)
                    <li class="flex items-center justify-between"><x-estado :estado="$estado"/><span class="text-sm font-semibold text-tinta">{{ $n }}</span></li>
                @empty
                    <li class="text-sm text-slate-400">Aún no hay documentos.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
