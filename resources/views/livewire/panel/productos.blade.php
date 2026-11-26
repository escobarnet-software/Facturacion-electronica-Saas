<div class="space-y-4">
    <h1 >Productos y servicios</h1>
    <p class="-mt-2 text-sm text-slate-500">Su catálogo: precios sin IVA, códigos para la Caja rápida y tipo de venta.</p>
    <form wire:submit="guardar" class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4" novalidate>
        <x-campo etiqueta="Código" campo="form.codigo" ayuda="Opcional, hasta 25 caracteres. Se usa en la Caja rápida.">
            <input wire:model.blur="form.codigo" maxlength="25" autocomplete="off">
        </x-campo>
        <x-campo etiqueta="Código de barras" campo="form.codigo_barras" ayuda="Opcional, para el lector.">
            <input wire:model.blur="form.codigo_barras" maxlength="50" inputmode="numeric" autocomplete="off">
        </x-campo>
        <x-campo etiqueta="Descripción" campo="form.descripcion" requerido class="sm:col-span-2" ayuda="Como aparecerá en el documento. Máximo 1500 caracteres.">
            <input wire:model.blur="form.descripcion" maxlength="1500">
        </x-campo>
        <x-campo etiqueta="Precio sin IVA" campo="form.precio" requerido ayuda="Hasta 8 decimales, sin comas ni $. Ej.: 0.66371681">
            <input wire:model.blur="form.precio" maxlength="20" inputmode="decimal">
        </x-campo>
        <x-campo etiqueta="Tipo de venta" campo="form.tipo_venta" requerido>
            <select wire:model.blur="form.tipo_venta"><option value="gravada">Gravada</option><option value="exenta">Exenta</option><option value="no_sujeta">No sujeta</option></select>
        </x-campo>
        <x-campo etiqueta="Tipo de ítem (CAT-011)" campo="form.tipo_item" requerido>
            <select wire:model.blur="form.tipo_item">@foreach ($tiposItem as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
        </x-campo>
        <x-campo etiqueta="Unidad de medida (CAT-014)" campo="form.uni_medida" requerido>
            <select wire:model.blur="form.uni_medida">@foreach ($unidades as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
        </x-campo>
        <div class="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
            <button class="btn-primario">{{ $editando ? 'Guardar cambios' : 'Agregar producto' }}</button>
        </div>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Código</th><th>Descripción</th><th class="text-right">Precio sin IVA</th><th>Venta</th><th></th></tr></thead>
        <tbody>@foreach ($productos as $p)<tr class="border-t"><td class="p-2">{{ $p->codigo }}</td><td>{{ $p->descripcion }}</td><td class="text-right">${{ number_format((float) $p->precio, 2) }}</td><td>{{ $p->tipo_venta }}</td><td><button wire:click="editar({{ $p->id }})" class="underline">Editar</button></td></tr>@endforeach</tbody>
    </table></div>
    {{ $productos->links() }}
</div>
