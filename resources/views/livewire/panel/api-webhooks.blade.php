<div class="space-y-4">
    <h1 >API y webhooks</h1>
    <p class="-mt-2 text-sm text-slate-500">Conecte su POS o ERP con tokens y reciba avisos automáticos (webhooks).</p>
    <p class="text-sm text-slate-600">Conecte su POS o ERP. Documentación: <a class="underline" href="{{ route('api.v1.openapi') }}" target="_blank">OpenAPI</a>.</p>

    @if ($secretoNuevo)
        <div class="alerta-aviso" role="alert">
            Copie este valor ahora: <strong>no se volverá a mostrar</strong>.
            <code class="mt-1 block break-all font-mono">{{ $secretoNuevo }}</code>
        </div>
    @endif
    @if ($error)<div class="alerta-error" role="alert">{{ $error }}</div>@endif

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Tokens de la API</h2>
        <form wire:submit="crearToken" class="space-y-2">
            <input wire:model="nombreToken" class="w-full " placeholder="Nombre (por ejemplo: POS sucursal centro)" aria-label="Nombre del token">
            @error('nombreToken')<p class="text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                @foreach ($catalogoPermisos as $clave => $etiqueta)
                    <label><input type="checkbox" wire:model="permisos" value="{{ $clave }}"> {{ $etiqueta }}</label>
                @endforeach
            </div>
            @error('permisos')<p class="text-red-700">{{ $message }}</p>@enderror
            <button class="btn-primario">Crear token</button>
        </form>
        <div class="overflow-x-auto"><table class="mt-3 w-full">
            <thead><tr class="bg-slate-100 text-left"><th class="p-1">Nombre</th><th>Permisos</th><th>Último uso</th><th></th></tr></thead>
            <tbody>
            @forelse ($tokens as $t)
                <tr class="border-t" wire:key="t-{{ $t->id }}">
                    <td class="p-1">{{ $t->name }}</td>
                    <td>{{ implode(', ', $t->abilities) }}</td>
                    <td>{{ $t->last_used_at?->setTimezone('America/El_Salvador')->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                    <td><button wire:click="revocarToken({{ $t->id }})" wire:confirm="¿Revocar este token? Los sistemas que lo usan dejarán de funcionar." class="underline text-red-700">Revocar</button></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-1 text-slate-500">No hay tokens.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Webhooks</h2>
        <form wire:submit="crearWebhook" class="space-y-2">
            <input wire:model="urlWebhook" class="w-full " placeholder="https://su-sistema.com/webhooks/dte" aria-label="URL del webhook">
            @error('urlWebhook')<p class="text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                @foreach ($catalogoEventos as $clave => $etiqueta)
                    <label><input type="checkbox" wire:model="eventos" value="{{ $clave }}"> {{ $etiqueta }}</label>
                @endforeach
            </div>
            <button class="btn-primario">Agregar webhook</button>
        </form>
        <ul class="mt-3 space-y-1">
            @forelse ($webhooks as $w)
                <li wire:key="w-{{ $w->id }}">{{ $w->url }} — {{ implode(', ', $w->eventos) }}
                    <button wire:click="eliminarWebhook({{ $w->id }})" wire:confirm="¿Eliminar este webhook?" class="underline text-red-700">Eliminar</button></li>
            @empty
                <li class="text-slate-500">No hay webhooks.</li>
            @endforelse
        </ul>
        @if ($entregas->isNotEmpty())
            <h3 class="mt-3 font-semibold">Últimas entregas</h3>
            <div class="overflow-x-auto"><table class="w-full">
                <thead><tr class="bg-slate-100 text-left"><th class="p-1">Evento</th><th>Estado</th><th>Intentos</th><th>Última respuesta</th></tr></thead>
                <tbody>
                @foreach ($entregas as $e)
                    <tr class="border-t"><td class="p-1">{{ $e->evento }}</td><td>{{ ucfirst($e->estado) }}</td><td>{{ $e->intentos }}</td><td>{{ $e->ultimo_error ?? ($e->ultimo_status ? 'HTTP '.$e->ultimo_status : '') }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</div>
