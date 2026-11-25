<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Planes</h1>
    <form wire:submit="guardar" class="grid gap-2 rounded border bg-white p-3 text-sm sm:grid-cols-3">
        <input wire:model="plan.codigo" placeholder="Código (ej.: basico)" class="rounded border p-1" aria-label="Código">
        <input wire:model="plan.nombre" placeholder="Nombre" class="rounded border p-1" aria-label="Nombre">
        <input wire:model="plan.precio_mensual" placeholder="Precio mensual sin IVA" class="rounded border p-1" aria-label="Precio mensual">
        <input wire:model="plan.documentos_mes" placeholder="Documentos al mes (vacío = ilimitado)" class="rounded border p-1" aria-label="Documentos al mes">
        <input wire:model="plan.precio_documento_adicional" placeholder="Precio por documento adicional" class="rounded border p-1" aria-label="Precio adicional">
        <input wire:model="plan.max_establecimientos" placeholder="Máx. establecimientos" class="rounded border p-1" aria-label="Máximo de establecimientos">
        <input wire:model="plan.max_usuarios" placeholder="Máx. usuarios" class="rounded border p-1" aria-label="Máximo de usuarios">
        <label><input type="checkbox" wire:model="plan.incluye_api"> Incluye API</label>
        <label><input type="checkbox" wire:model="plan.incluye_pos"> Incluye POS</label>
        <button class="rounded bg-blue-700 px-3 py-1 text-white sm:col-span-3">Crear plan</button>
        @foreach ($errors->all() as $e)<p class="text-red-700 sm:col-span-3">{{ $e }}</p>@endforeach
    </form>
    <table class="w-full rounded border bg-white text-sm">
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
    </table>
</div>
