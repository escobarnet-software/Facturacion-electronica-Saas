<div class="space-y-6">
    <div>
        <h1>Usuarios</h1>
        <p class="mt-1 text-sm text-slate-500">Invite a su contador, cajeros o personas de consulta. Cada uno recibe un correo para crear su propia contraseña; nunca comparta la suya.</p>
    </div>
    @if ($mensaje)<div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>{{ $mensaje }}</div>@endif
    @if ($error)<div class="alerta-error" role="alert"><x-icono nombre="alerta" clase="size-5 shrink-0"/>{{ $error }}</div>@endif

    <form wire:submit="invitar" class="card" novalidate>
        <div class="card-titulo"><x-icono nombre="correo" clase="size-5 text-marca-600"/>Invitar a una persona</div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-campo etiqueta="Nombre" campo="nuevo.nombre" requerido><input wire:model="nuevo.nombre" maxlength="100" autocomplete="off"></x-campo>
            <x-campo etiqueta="Correo" campo="nuevo.email" requerido ayuda="Le llegará la invitación."><input type="email" wire:model="nuevo.email" maxlength="255" autocomplete="off"></x-campo>
            <x-campo etiqueta="Rol" campo="nuevo.rol" requerido>
                <select wire:model.live="nuevo.rol">@foreach ($roles as $v => $n)<option value="{{ $v }}">{{ $n }}</option>@endforeach</select>
            </x-campo>
            @if ($nuevo['rol'] === 'cajero')
                <x-campo etiqueta="Caja (punto de venta)" campo="nuevo.punto_venta_id" requerido>
                    <select wire:model="nuevo.punto_venta_id"><option value="">Elija…</option>@foreach ($puntosVenta as $pv)<option value="{{ $pv->id }}">{{ $pv->establecimiento->cod_estable_mh }}{{ $pv->cod_punto_venta_mh }} · {{ $pv->nombre }}</option>@endforeach</select>
                </x-campo>
            @endif
        </div>
        <div class="mt-4 grid gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 sm:grid-cols-4">
            <div><strong class="text-tinta">Dueño:</strong> todo, incluida la configuración, Hacienda y los usuarios. Doble factor obligatorio.</div>
            <div><strong class="text-tinta">Contador:</strong> emitir, invalidar, recibidos, libros de IVA y catálogos.</div>
            <div><strong class="text-tinta">Cajero:</strong> solo emitir desde su caja y ver lo de su caja.</div>
            <div><strong class="text-tinta">Consulta:</strong> solo ver documentos.</div>
        </div>
        <div class="mt-4 flex justify-end"><button class="btn-primario"><x-icono nombre="enviar" clase="size-4"/>Enviar invitación</button></div>
    </form>

    <div class="card overflow-hidden !p-0">
        <div class="overflow-x-auto"><table>
            <thead><tr><th>Persona</th><th>Rol</th><th>Caja</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
            <tbody>
            @foreach ($miembros as $u)
                <tr wire:key="u-{{ $u->id }}">
                    <td><div class="font-medium text-tinta">{{ $u->nombre }} @if ($u->id === auth()->id())<span class="insignia-gris">usted</span>@endif</div><div class="text-xs text-slate-500">{{ $u->email }}</div></td>
                    <td>
                        @if ($editando === $u->id)
                            <select wire:model.live="edicion.rol" class="!py-1" aria-label="Rol">@foreach ($roles as $v => $n)<option value="{{ $v }}">{{ $n }}</option>@endforeach</select>
                        @else
                            <span class="{{ $u->membresia->rol === 'dueno' ? 'insignia-azul' : 'insignia-gris' }}">{{ $roles[$u->membresia->rol] ?? $u->membresia->rol }}</span>
                        @endif
                    </td>
                    <td class="text-sm">
                        @if ($editando === $u->id && $edicion['rol'] === 'cajero')
                            <select wire:model="edicion.punto_venta_id" class="!py-1" aria-label="Caja"><option value="">Elija…</option>@foreach ($puntosVenta as $pv)<option value="{{ $pv->id }}">{{ $pv->establecimiento->cod_estable_mh }}{{ $pv->cod_punto_venta_mh }} · {{ $pv->nombre }}</option>@endforeach</select>
                            @error('edicion.punto_venta_id')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
                        @else
                            {{ $u->membresia->punto_venta_id ? optional($puntosVenta->firstWhere('id', $u->membresia->punto_venta_id))->nombre : '—' }}
                        @endif
                    </td>
                    <td>@if ($u->email_verified_at)<span class="insignia-verde">Activo</span>@else<span class="insignia-ambar">Invitación pendiente</span>@endif</td>
                    <td>
                        <div class="flex justify-end gap-1 whitespace-nowrap">
                            @if ($editando === $u->id)
                                <button wire:click="guardarRol" class="btn-primario !px-2.5 !py-1 text-xs">Guardar</button>
                                <button wire:click="$set('editando', null)" class="btn-secundario !px-2.5 !py-1 text-xs">Cancelar</button>
                            @else
                                <button wire:click="editar({{ $u->id }})" class="btn-secundario !px-2.5 !py-1 text-xs">Cambiar rol</button>
                                @if (! $u->email_verified_at)<button wire:click="reenviar({{ $u->id }})" class="btn-secundario !px-2.5 !py-1 text-xs">Reenviar</button>@endif
                                @if ($u->id !== auth()->id())<button wire:click="quitar({{ $u->id }})" wire:confirm="¿Quitar el acceso de {{ $u->nombre }} a esta empresa?" class="btn-secundario !px-2.5 !py-1 text-xs !text-rose-700">Quitar</button>@endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
</div>
