<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Documentos recibidos</h1>
    <p class="text-sm text-slate-600">Suba los archivos JSON que le envían sus proveedores. Solo se aceptan documentos completos (con firma y sello de Hacienda), emitidos a nombre de su empresa y sin alteraciones.</p>

    <form wire:submit="importar" class="space-y-2 rounded border bg-white p-3">
        <input type="file" wire:model="archivos" multiple accept=".json,application/json" aria-label="Archivos JSON">
        @error('archivos')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        @error('archivos.*')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button class="rounded bg-blue-700 px-3 py-2 text-sm text-white">Registrar documentos</button>
    </form>
    @foreach ($mensajes as $m)<div class="rounded border border-blue-300 bg-blue-50 p-2 text-sm" role="status">{{ $m }}</div>@endforeach

    <div class="rounded border bg-white p-3 text-sm">
        <h2 class="font-semibold">Buzón de correo</h2>
        @if ($buzon)
            <p>Dirección de entrega para el servicio de correo entrante: <code class="break-all">{{ $buzon }}</code></p>
        @else
            <p class="text-slate-600">Aún no tiene buzón de entrada.</p>
        @endif
        @can('administrar-empresa')<button wire:click="generarBuzon" class="mt-2 rounded border px-3 py-1">{{ $buzon ? 'Cambiar dirección' : 'Crear buzón' }}</button>@endcan
    </div>

    <div class="overflow-x-auto rounded border bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-100 text-left"><th class="p-2">Fecha</th><th>Tipo</th><th>Número de control</th><th>Proveedor</th><th class="text-right">Gravado</th><th class="text-right">IVA</th><th class="text-right">Total</th><th>Origen</th></tr></thead>
            <tbody>
            @forelse ($recibidos as $r)
                <tr class="border-t" wire:key="r-{{ $r->id }}">
                    <td class="p-2">{{ $r->fecha_emision->format('d/m/Y') }}</td>
                    <td>{{ $r->tipo }}</td>
                    <td class="font-mono text-xs">{{ $r->numero_control }}</td>
                    <td>{{ $r->emisor_nombre }}<br><span class="text-xs text-slate-500">NIT {{ $r->emisor_nit }}</span></td>
                    <td class="text-right">${{ $r->getRawOriginal('total_gravada') }}</td>
                    <td class="text-right">${{ $r->getRawOriginal('total_iva') }}</td>
                    <td class="text-right">${{ $r->getRawOriginal('total_pagar') }}</td>
                    <td>{{ $r->origen === 'correo' ? 'Correo' : 'Subido' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-3 text-slate-500">Todavía no hay documentos recibidos.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $recibidos->links() }}
</div>
