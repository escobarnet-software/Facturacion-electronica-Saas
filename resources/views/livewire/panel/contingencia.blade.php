<div class="space-y-4" wire:poll.60s>
    <h1 class="text-2xl font-semibold">Contingencia</h1>
    @if ($actual)
        <div class="rounded border border-amber-300 bg-amber-50 p-4">
            <p><strong>Estado:</strong> {{ ['activa' => 'Activa: Hacienda no responde', 'cerrada' => 'Servicio restablecido: enviando el evento', 'evento_sellado' => 'Evento sellado: transmitiendo documentos'][$actual->estado] ?? $actual->estado }}</p>
            <p><strong>Inicio:</strong> {{ $actual->inicio->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}
                @if ($actual->fin) · <strong>Fin:</strong> {{ $actual->fin->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}@endif</p>
            <p><strong>Último intento:</strong> {{ $actual->ultimo_intento_at?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') ?? 'aún no' }} (se reintenta cada 15 minutos)</p>
            @if ($evento)<p><strong>Evento:</strong> {{ $evento->codigo_generacion }} · {{ $evento->estado }} {{ $evento->sello_recibido ? '· sello '.$evento->sello_recibido : '' }}</p>@endif
        </div>
        <h2 class="font-semibold">Documentos pendientes ({{ $pendientes->count() }})</h2>
        <ul class="list-inside list-disc text-sm">@foreach ($pendientes as $d)<li class="font-mono">{{ $d->numero_control }} · {{ $d->estado->etiqueta() }}</li>@endforeach</ul>
        @if ($actual->informe_borrador)
            <h2 class="font-semibold">Borrador del Informe Técnico de Contingencia</h2>
            <pre class="whitespace-pre-wrap rounded border bg-white p-3 text-sm">{{ $actual->informe_borrador }}</pre>
        @endif
    @else
        <p class="rounded border border-green-300 bg-green-50 p-4">No hay contingencias abiertas: la transmisión a Hacienda funciona con normalidad.</p>
    @endif

    <h2 class="font-semibold">Historial</h2>
    <ul class="text-sm">@foreach ($historial as $c)<li>{{ $c->inicio->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }} → {{ $c->fin?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') ?? '…' }} · {{ $c->estado }}</li>@endforeach</ul>
</div>
