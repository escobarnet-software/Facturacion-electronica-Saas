<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Empresas</h1>
    @if ($mensaje)<div class="rounded border border-blue-300 bg-blue-50 p-2 text-sm" role="status">{{ $mensaje }}</div>@endif
    @if ($invitacionEnviada)
        <div class="rounded border border-blue-300 bg-blue-50 p-2 text-sm" role="status">Se envió la invitación a {{ $invitacionEnviada }} (vence en 72 horas).</div>
    @endif

    <form wire:submit="crear" class="grid gap-2 rounded border bg-white p-3 text-sm sm:grid-cols-4">
        <input wire:model="nueva.nit" placeholder="NIT (sin guiones)" class="rounded border p-1" aria-label="NIT">
        <input wire:model="nueva.nrc" placeholder="NRC" class="rounded border p-1" aria-label="NRC">
        <input wire:model="nueva.razon_social" placeholder="Razón social" class="rounded border p-1 sm:col-span-2" aria-label="Razón social">
        <input wire:model="nueva.correo" placeholder="Correo de la empresa" class="rounded border p-1" aria-label="Correo">
        <select wire:model="nueva.plan_id" class="rounded border p-1" aria-label="Plan"><option value="">Plan…</option>@foreach ($planes as $p)<option value="{{ $p->id }}">{{ $p->nombre }}</option>@endforeach</select>
        <input wire:model="nueva.dueno_nombre" placeholder="Nombre del dueño" class="rounded border p-1" aria-label="Nombre del dueño">
        <input wire:model="nueva.dueno_email" placeholder="Correo del dueño" class="rounded border p-1" aria-label="Correo del dueño">
        <button class="rounded bg-blue-700 px-3 py-1 text-white sm:col-span-4">Dar de alta</button>
        @foreach ($errors->all() as $e)<p class="text-red-700 sm:col-span-4">{{ $e }}</p>@endforeach
    </form>

    <div class="overflow-x-auto rounded border bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-100 text-left"><th class="p-2">Empresa</th><th>NIT</th><th>Ambiente</th><th>Plan</th><th class="text-right">Documentos del mes</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            @foreach ($filas as $f)
                <tr class="border-t" wire:key="e-{{ $f['id'] }}">
                    <td class="p-2">{{ $f['razon_social'] }}</td>
                    <td>{{ $f['nit'] }}</td>
                    <td>{{ $f['ambiente'] === '01' ? 'Producción' : 'Pruebas' }}</td>
                    <td>
                        <select wire:change="cambiarPlan({{ $f['id'] }}, $event.target.value)" class="rounded border p-1" aria-label="Plan de {{ $f['razon_social'] }}">
                            <option value="">Sin plan</option>
                            @foreach ($planes as $p)<option value="{{ $p->id }}" @selected($f['plan']?->id === $p->id)>{{ $p->nombre }}</option>@endforeach
                        </select>
                    </td>
                    <td class="text-right">{{ $f['documentos'] }}@if ($f['plan']?->documentos_mes) / {{ $f['plan']->documentos_mes }}@endif</td>
                    <td>{{ ucfirst($f['estado']) }}</td>
                    <td class="space-x-2 whitespace-nowrap">
                        @if ($f['estado'] === 'suspendida')
                            <button wire:click="cambiarEstado({{ $f['id'] }}, 'activa')" class="underline">Reactivar</button>
                        @else
                            <button wire:click="cambiarEstado({{ $f['id'] }}, 'suspendida')" wire:confirm="¿Suspender esta empresa? Perderá el acceso al panel y a la API." class="underline text-red-700">Suspender</button>
                        @endif
                        @if ($f['soporte'])
                            <form method="POST" action="{{ route('admin.soporte.entrar', $f['id']) }}" class="inline">@csrf<button class="underline">Entrar como soporte</button></form>
                        @else
                            <span class="text-slate-400" title="La empresa no ha dado permiso de soporte">Sin permiso de soporte</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
