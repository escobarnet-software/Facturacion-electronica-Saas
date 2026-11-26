<div class="space-y-4">
    <h1>Planes</h1>
    <form wire:submit="guardar" class="grid gap-2 card text-sm sm:grid-cols-3">
        <input wire:model="plan.codigo" placeholder="Código (ej.: basico)"  aria-label="Código">
        <input wire:model="plan.nombre" placeholder="Nombre"  aria-label="Nombre">
        <input wire:model="plan.precio_mensual" placeholder="Precio mensual sin IVA"  aria-label="Precio mensual">
        <input wire:model="plan.documentos_mes" placeholder="Documentos al mes (vacío = ilimitado)"  aria-label="Documentos al mes">
        <input wire:model="plan.precio_documento_adicional" placeholder="Precio por documento adicional"  aria-label="Precio adicional">
        <input wire:model="plan.max_establecimientos" placeholder="Máx. establecimientos"  aria-label="Máximo de establecimientos">
        <input wire:model="plan.max_usuarios" placeholder="Máx. usuarios"  aria-label="Máximo de usuarios">
        <label><input type="checkbox" wire:model="plan.incluye_api"> Incluye API</label>
        <label><input type="checkbox" wire:model="plan.incluye_pos"> Incluye POS</label>
        <button class="btn-primario sm:col-span-3">Crear plan</button>
        @foreach ($errors->all() as $e)<p class="text-red-700 sm:col-span-3">{{ $e }}</p>@endforeach
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Plan</th><th class="text-right">Precio</th><th class="text-right">Documentos</th><th class="text-right">Adicional</th><th>Límites</th><th>Estado</th></tr></thead>
        <tbody>
        @foreach ($planes as $p)
            <tr class="border-t" wire:key="p-{{ $p->id }}">
                <td class="p-2">{{ $p->nombre }} <span class="text-slate-500">({{ $p->codigo }})</span></td>
                <td class="text-right">${{ $p->getRawOriginal('precio_mensual') }}</td>
                <td class="text-right">{{ $p->documentos_mes ?? 'Ilimitado' }}</td>
                <td class="text-right">${{ rtrim(rtrim($p->getRawOriginal('precio_documento_adicional'), '0'), '.') ?: '0' }}</td>
                <td>{{ $p->max_establecimientos ?? '∞' }} est. · {{ $p->max_usuarios ?? '∞' }} usuarios · {{ $p->incluye_api ? 'API' : 'sin API' }} · {{ $p->incluye_pos ? 'POS' : 'sin POS' }}</td>
                <td><button wire:click="alternar({{ $p->id }})" class="underline">{{ $p->activo ? 'Activo' : 'Inactivo' }}</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
