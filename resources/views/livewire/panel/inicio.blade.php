<div class="space-y-6">
    <h1 class="text-2xl font-semibold">Inicio</h1>

    <div class="space-y-2">
        @if ($rechazados > 0)
            <div class="rounded border border-red-300 bg-red-50 p-3 text-red-800">Hay {{ $rechazados }} documento(s) rechazado(s) por Hacienda: corríjalos dentro de 24 horas.</div>
        @endif
        @if ($contingencia)
            <div class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-800">Contingencia activa: los documentos se entregan y se transmitirán al volver el servicio.</div>
        @endif
        @if ($credencialesBloqueadas)
            <div class="rounded border border-red-300 bg-red-50 p-3 text-red-800">Las credenciales de Hacienda fallaron y se dejaron de usar. Cárguelas de nuevo.</div>
        @endif
        @if ($invalidacionesPorVencer > 0)
            <div class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-800">{{ $invalidacionesPorVencer }} documento(s) con plazo de invalidación por vencer en 3 días.</div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded border bg-white p-4"><div class="text-sm text-slate-500">Ventas de hoy</div><div class="text-2xl font-semibold">${{ number_format((float) $ventasHoy, 2) }}</div></div>
        <div class="rounded border bg-white p-4"><div class="text-sm text-slate-500">Ventas del mes</div><div class="text-2xl font-semibold">${{ number_format((float) $ventasMes, 2) }}</div></div>
    </div>

    <div class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">Documentos por estado</h2>
        <ul class="grid gap-1 sm:grid-cols-3">
            @forelse ($porEstado as $estado => $n)
                <li>{{ \App\Enums\EstadoDte::from($estado)->etiqueta() }}: <strong>{{ $n }}</strong></li>
            @empty
                <li class="text-slate-500">Aún no hay documentos.</li>
            @endforelse
        </ul>
    </div>
</div>
