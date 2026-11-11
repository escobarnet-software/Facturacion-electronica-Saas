<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Documentos emitidos</h1>
        <button wire:click="exportar" class="rounded border bg-white px-3 py-2 text-sm">Exportar a Excel</button>
    </div>
    @if ($mensaje)<div class="rounded border border-blue-300 bg-blue-50 p-3" role="status">{{ $mensaje }}</div>@endif

    <div class="grid gap-2 rounded border bg-white p-3 text-sm sm:grid-cols-6">
        <input type="date" wire:model.live="desde" class="rounded border p-1" aria-label="Desde">
        <input type="date" wire:model.live="hasta" class="rounded border p-1" aria-label="Hasta">
        <select wire:model.live="tipo" class="rounded border p-1" aria-label="Tipo"><option value="">Todos los tipos</option>
            @foreach (['01' => 'Factura', '03' => 'Crédito Fiscal', '04' => 'Nota de Remisión', '05' => 'Nota de Crédito', '06' => 'Nota de Débito', '07' => 'Retención', '08' => 'Liquidación', '09' => 'Contable de Liquidación', '11' => 'Exportación', '14' => 'Sujeto Excluido', '15' => 'Donación'] as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach
        </select>
        <select wire:model.live="estado" class="rounded border p-1" aria-label="Estado"><option value="">Todos los estados</option>
            @foreach (\App\Enums\EstadoDte::cases() as $e)<option value="{{ $e->value }}">{{ $e->etiqueta() }}</option>@endforeach
        </select>
        <input wire:model.live.debounce.400ms="receptor" class="rounded border p-1" placeholder="Receptor o documento">
        <input wire:model.live.debounce.400ms="codigo" class="rounded border p-1" placeholder="Código o número de control">
    </div>

    <div class="overflow-x-auto rounded border bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-100 text-left"><th class="p-2">Tipo</th><th>Número de control</th><th>Receptor</th><th>Documento</th><th>Generación</th><th>Recepción</th><th class="text-right">Monto</th><th class="text-right">IVA</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            @forelse ($documentos as $d)
                <tr class="border-t {{ $d->estado === \App\Enums\EstadoDte::Rechazado ? 'bg-red-50 text-red-800' : '' }}" wire:key="d-{{ $d->id }}">
                    <td class="p-2">{{ $d->tipo }}</td>
                    <td class="font-mono text-xs">{{ $d->numero_control }}</td>
                    <td>{{ $d->receptor_nombre ?? 'Consumidor final' }}</td>
                    <td>{{ $d->receptor_documento }}</td>
                    <td>{{ $d->fecha_emision->format('d/m/Y') }}</td>
                    <td>{{ $d->fh_procesamiento?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}</td>
                    <td class="text-right">${{ number_format((float) $d->getRawOriginal('total_pagar'), 2) }}</td>
                    <td class="text-right">${{ number_format((float) $d->getRawOriginal('total_iva'), 2) }}</td>
                    <td>{{ $d->estado->etiqueta() }}</td>
                    <td class="space-x-2 whitespace-nowrap">
                        @if ($d->jws)
                            <a class="underline" href="{{ route('panel.documentos.pdf', $d) }}" target="_blank">PDF</a>
                            <a class="underline" href="{{ route('panel.documentos.json', $d) }}">JSON</a>
                        @endif
                        @can('emitir')@if (in_array($d->estado, [\App\Enums\EstadoDte::Rechazado, \App\Enums\EstadoDte::Firmado, \App\Enums\EstadoDte::Borrador], true))<button wire:click="reenviar({{ $d->id }})" class="underline">Reenviar</button>@endif @endcan
                        @can('invalidar')@if ($d->sello_recibido && $d->estado !== \App\Enums\EstadoDte::Invalidado)<a class="underline" href="{{ route('panel.invalidaciones', ['dte' => $d->id]) }}">Invalidar</a>@endif @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="p-3 text-slate-500">No hay documentos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $documentos->links() }}
</div>
