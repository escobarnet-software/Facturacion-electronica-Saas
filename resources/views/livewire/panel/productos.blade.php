<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Productos y servicios</h1>
    <form wire:submit="guardar" class="grid gap-2 rounded border bg-white p-4 sm:grid-cols-4">
        <label>Código<input wire:model="form.codigo" class="mt-1 w-full rounded border p-2"></label>
        <label>Código de barras<input wire:model="form.codigo_barras" class="mt-1 w-full rounded border p-2"></label>
        <label class="sm:col-span-2">Descripción<input wire:model="form.descripcion" class="mt-1 w-full rounded border p-2"></label>
        <label>Precio sin IVA<input wire:model="form.precio" class="mt-1 w-full rounded border p-2" inputmode="decimal"></label>
        <label>Tipo de venta<select wire:model="form.tipo_venta" class="mt-1 w-full rounded border p-2"><option value="gravada">Gravada</option><option value="exenta">Exenta</option><option value="no_sujeta">No sujeta</option></select></label>
        <label>Tipo de ítem<select wire:model="form.tipo_item" class="mt-1 w-full rounded border p-2"><option value="1">Bienes</option><option value="2">Servicios</option><option value="3">Ambos</option></select></label>
        <label>Unidad (CAT-014)<input type="number" wire:model="form.uni_medida" class="mt-1 w-full rounded border p-2"></label>
        @if ($errors->any())<div class="text-sm text-red-700 sm:col-span-4">{{ $errors->first() }}</div>@endif
        <button class="rounded bg-blue-700 py-2 text-white sm:col-span-4">{{ $editando ? 'Guardar cambios' : 'Agregar producto' }}</button>
    </form>
    <table class="w-full rounded border bg-white text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Código</th><th>Descripción</th><th class="text-right">Precio sin IVA</th><th>Venta</th><th></th></tr></thead>
        <tbody>@foreach ($productos as $p)<tr class="border-t"><td class="p-2">{{ $p->codigo }}</td><td>{{ $p->descripcion }}</td><td class="text-right">${{ number_format((float) $p->precio, 2) }}</td><td>{{ $p->tipo_venta }}</td><td><button wire:click="editar({{ $p->id }})" class="underline">Editar</button></td></tr>@endforeach</tbody>
    </table>
    {{ $productos->links() }}
</div>
