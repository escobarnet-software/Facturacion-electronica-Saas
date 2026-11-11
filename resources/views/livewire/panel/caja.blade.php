<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-2xl font-semibold">Caja rápida</h1>
    @if ($error)<div class="rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">{{ $error }}</div>@endif
    @if ($mensaje)<div class="rounded border border-green-300 bg-green-50 p-3 text-green-800" role="status">{{ $mensaje }}
        @if ($ultimo)<a class="underline" target="_blank" href="{{ route('panel.documentos.pdf', ['dte' => $ultimo->id, 'formato' => 'ticket']) }}">Imprimir ticket</a>@endif
    </div>@endif

    <form wire:submit="escanear" class="flex gap-2">
        <input wire:model="codigo" autofocus class="flex-1 rounded border p-3 text-lg" placeholder="Escanee o escriba el código" aria-label="Código del producto">
        <button class="rounded bg-slate-700 px-4 text-white">Agregar</button>
    </form>

    <table class="w-full rounded border bg-white text-sm">
        <thead><tr class="text-left text-slate-500"><th class="p-2">Producto</th><th>Cant.</th><th class="text-right">Precio</th><th></th></tr></thead>
        <tbody>
        @forelse ($carrito as $i => $l)
            <tr wire:key="c-{{ $i }}"><td class="p-2">{{ $l['descripcion'] }}</td><td><input wire:model.live.debounce.300ms="carrito.{{ $i }}.cantidad" class="w-16 rounded border p-1"></td>
                <td class="text-right">${{ number_format((float) $l['precio'] * 1.13, 2) }}</td><td><button wire:click="quitar({{ $i }})" class="px-2 text-red-700" aria-label="Quitar">✕</button></td></tr>
        @empty
            <tr><td colspan="4" class="p-2 text-slate-500">Sin productos.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="flex items-center justify-between rounded border bg-white p-4">
        <select wire:model="formaPago" class="rounded border p-2"><option value="01">Efectivo</option><option value="02">Tarjeta débito</option><option value="03">Tarjeta crédito</option></select>
        <div class="text-2xl font-semibold">Total ${{ $total }}</div>
        <button wire:click="cobrar" wire:loading.attr="disabled" class="rounded bg-blue-700 px-6 py-3 text-white">Cobrar</button>
    </div>
</div>
