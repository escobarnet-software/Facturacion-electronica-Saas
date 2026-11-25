<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Cobros</h1>
    <label class="text-sm">Período <input type="month" wire:model.live="periodo" class="rounded border p-1"></label>
    <p class="text-sm text-slate-600">Los cobros se calculan el día 1 de cada mes (<code>plataforma:facturar</code>). Los pagos se registran a mano mientras se define la pasarela de cobro.</p>
    <table class="w-full rounded border bg-white text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Empresa</th><th class="text-right">Plan</th><th class="text-right">Docs.</th><th class="text-right">Adicionales</th><th class="text-right">Subtotal</th><th>DTE</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        @forelse ($cobros as $c)
            <tr class="border-t" wire:key="c-{{ $c->id }}">
                <td class="p-2">{{ $empresas[$c->empresa_id] ?? $c->empresa_id }}</td>
                <td class="text-right">${{ $c->getRawOriginal('cargo_plan') }}</td>
                <td class="text-right">{{ $c->documentos }}</td>
                <td class="text-right">{{ $c->excedente }} (${{ $c->getRawOriginal('cargo_excedente') }})</td>
                <td class="text-right">${{ $c->getRawOriginal('subtotal') }}</td>
                <td class="font-mono text-xs">{{ $c->dte_codigo_generacion ?? '—' }}</td>
                <td>{{ ucfirst($c->estado) }}</td>
                <td>
                    @if ($c->estado === 'pendiente')
                        <input wire:model="referencias.{{ $c->id }}" placeholder="Referencia" class="w-32 rounded border p-1" aria-label="Referencia del pago">
                        <button wire:click="registrarPago({{ $c->empresa_id }}, {{ $c->id }})" class="underline">Registrar pago</button>
                        @error("referencias.{$c->id}")<p class="text-red-700">{{ $message }}</p>@enderror
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="p-2 text-slate-500">No hay cobros en este período.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
