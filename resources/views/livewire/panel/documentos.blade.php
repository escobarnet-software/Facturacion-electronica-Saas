<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1>Documentos emitidos</h1>
            <p class="mt-1 text-sm text-slate-500">Busque, descargue el PDF o el JSON, reenvíe los rechazados o invalide dentro del plazo.</p>
        </div>
        <div class="flex gap-2">
            <button wire:click="exportar" class="btn-secundario"><x-icono nombre="descargar" clase="size-4"/>Exportar a Excel</button>
            @can('emitir')<a href="{{ route('panel.emitir') }}" class="btn-primario"><x-icono nombre="mas" clase="size-4"/>Emitir</a>@endcan
        </div>
    </div>
    @if ($mensaje)<div class="alerta-info" role="status">{{ $mensaje }}</div>@endif

    <div class="card grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <label class="block text-xs">Desde<input type="date" wire:model.live="desde" class="mt-1 w-full" aria-label="Desde"></label>
        <label class="block text-xs">Hasta<input type="date" wire:model.live="hasta" class="mt-1 w-full" aria-label="Hasta"></label>
        <label class="block text-xs">Tipo
            <select wire:model.live="tipo" class="mt-1 w-full" aria-label="Tipo"><option value="">Todos los tipos</option>
                @foreach (['01' => 'Factura', '03' => 'Crédito Fiscal', '04' => 'Nota de Remisión', '05' => 'Nota de Crédito', '06' => 'Nota de Débito', '07' => 'Retención', '08' => 'Liquidación', '09' => 'Contable de Liquidación', '11' => 'Exportación', '14' => 'Sujeto Excluido', '15' => 'Donación'] as $c => $n)<option value="{{ $c }}">{{ $c }} · {{ $n }}</option>@endforeach
            </select>
        </label>
        <label class="block text-xs">Estado
            <select wire:model.live="estado" class="mt-1 w-full" aria-label="Estado"><option value="">Todos los estados</option>
                @foreach (\App\Enums\EstadoDte::cases() as $e)<option value="{{ $e->value }}">{{ $e->etiqueta() }}</option>@endforeach
            </select>
        </label>
        <label class="block text-xs">Cliente<input wire:model.live.debounce.400ms="receptor" class="mt-1 w-full" placeholder="Nombre o documento"></label>
        <label class="block text-xs">Código o número<input wire:model.live.debounce.400ms="codigo" class="mt-1 w-full" placeholder="DTE-01-… o código"></label>
    </div>

    <div class="card overflow-hidden !p-0">
        <div class="overflow-x-auto">
            <table>
                <thead><tr><th>Tipo</th><th>Número de control</th><th>Cliente</th><th>Emisión</th><th>Sello de Hacienda</th><th class="text-right">Total</th><th class="text-right">IVA</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
                <tbody>
                @forelse ($documentos as $d)
                    <tr wire:key="d-{{ $d->id }}" @class(['bg-rose-50/60' => $d->estado === \App\Enums\EstadoDte::Rechazado])>
                        <td><span class="insignia-gris">{{ $d->tipo }}</span></td>
                        <td class="font-mono text-xs whitespace-nowrap">{{ $d->numero_control }}</td>
                        <td><div class="font-medium text-tinta">{{ $d->receptor_nombre ?? 'Consumidor final' }}</div>@if ($d->receptor_documento)<div class="text-xs text-slate-500">{{ $d->receptor_documento }}</div>@endif</td>
                        <td class="whitespace-nowrap">{{ $d->fecha_emision->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap text-xs text-slate-500">{{ $d->fh_procesamiento?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-right font-semibold whitespace-nowrap">${{ number_format((float) $d->getRawOriginal('total_pagar'), 2) }}</td>
                        <td class="pr-3 text-right whitespace-nowrap">${{ number_format((float) $d->getRawOriginal('total_iva'), 2) }}</td>
                        <td><x-estado :estado="$d->estado"/></td>
                        <td>
                            <div class="flex justify-end gap-1 whitespace-nowrap">
                                @if ($d->jws)
                                    <a class="btn-secundario !px-2.5 !py-1 text-xs" href="{{ route('panel.documentos.pdf', $d) }}" target="_blank">PDF</a>
                                    <a class="btn-secundario !px-2.5 !py-1 text-xs" href="{{ route('panel.documentos.json', $d) }}">JSON</a>
                                @endif
                                @can('emitir')@if (in_array($d->estado, [\App\Enums\EstadoDte::Rechazado, \App\Enums\EstadoDte::Firmado, \App\Enums\EstadoDte::Borrador], true))<button wire:click="reenviar({{ $d->id }})" class="btn-primario !px-2.5 !py-1 text-xs">Reenviar</button>@endif @endcan
                                @can('invalidar')@if ($d->sello_recibido && $d->estado !== \App\Enums\EstadoDte::Invalidado)<a class="btn-secundario !px-2.5 !py-1 text-xs !text-rose-700" href="{{ route('panel.invalidaciones', ['dte' => $d->id]) }}">Invalidar</a>@endif @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-10 text-center text-slate-400">No hay documentos con esos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $documentos->links() }}
</div>
