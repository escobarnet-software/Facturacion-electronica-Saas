<div class="space-y-4">
    <h1 >Clientes</h1>
    <p class="-mt-2 text-sm text-slate-500">Clientes frecuentes. Para emitir Crédito Fiscal necesitan NRC, actividad económica y dirección.</p>
    <form wire:submit="guardar" class="grid gap-2 card sm:grid-cols-4">
        <label>Tipo de documento<select wire:model="form.tipo_documento" class="mt-1 w-full "><option value="36">NIT</option><option value="13">DUI</option><option value="03">Pasaporte</option><option value="02">Carnet de residente</option><option value="37">Otro</option></select></label>
        <label>Número<input wire:model="form.num_documento" class="mt-1 w-full "></label>
        <label>NRC<input wire:model="form.nrc" class="mt-1 w-full "></label>
        <label>Nombre<input wire:model="form.nombre" class="mt-1 w-full "></label>
        <label>Código de actividad<input wire:model="form.cod_actividad" class="mt-1 w-full "></label>
        <label class="sm:col-span-2">Actividad económica<input wire:model="form.desc_actividad" class="mt-1 w-full "></label>
        <label>Correo<input wire:model="form.correo" class="mt-1 w-full "></label>
        <label>Departamento<input wire:model="form.departamento" class="mt-1 w-full " placeholder="06"></label>
        <label>Municipio<input wire:model="form.municipio" class="mt-1 w-full " placeholder="23"></label>
        <label>Distrito<input wire:model="form.distrito" class="mt-1 w-full " placeholder="14"></label>
        <label>Dirección<input wire:model="form.complemento" class="mt-1 w-full "></label>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.gran_contribuyente"> Gran contribuyente</label>
        @if ($errors->any())<div class="text-sm text-red-700 sm:col-span-4">{{ $errors->first() }}</div>@endif
        <button class="btn-primario sm:col-span-4">{{ $editando ? 'Guardar cambios' : 'Agregar cliente' }}</button>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Nombre</th><th>Documento</th><th>NRC</th><th>Correo</th><th></th></tr></thead>
        <tbody>@foreach ($clientes as $c)<tr class="border-t"><td class="p-2">{{ $c->nombre }}</td><td>{{ \App\Livewire\Panel\Clientes::enmascarar($c->num_documento) }}</td><td>{{ $c->nrc }}</td><td>{{ $c->correo }}</td><td><button wire:click="editar({{ $c->id }})" class="underline">Editar</button></td></tr>@endforeach</tbody>
    </table></div>
    {{ $clientes->links() }}
</div>
