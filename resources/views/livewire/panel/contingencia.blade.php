<div class="space-y-4" wire:poll.60s>
    <h1 >Contingencia</h1>
    <p class="-mt-2 text-sm text-slate-500">Cuando Hacienda no responde, los documentos se entregan igual y aquí se ve su avance hasta obtener el sello.</p>
    @if ($actual)
        <div class="alerta-aviso block">
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
            <pre class="whitespace-pre-wrap card text-sm">{{ $actual->informe_borrador }}</pre>
        @endif
    @else
        <p class="alerta-exito">No hay contingencias abiertas: la transmisión a Hacienda funciona con normalidad.</p>
    @endif

    <h2 class="font-semibold">Historial</h2>
    <ul class="text-sm">@foreach ($historial as $c)<li>{{ $c->inicio->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }} → {{ $c->fin?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') ?? '…' }} · {{ $c->estado }}</li>@endforeach</ul>
</div>
