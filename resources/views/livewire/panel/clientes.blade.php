<div class="space-y-6">
    <div>
        <h1>Clientes</h1>
        <p class="mt-1 text-sm text-slate-500">Clientes frecuentes. Los campos con <span class="text-rose-600">*</span> los exige Hacienda; con NRC el cliente puede recibir Crédito Fiscal y se piden además la actividad y la dirección.</p>
    </div>
    @if (session('cliente_guardado'))<div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>{{ session('cliente_guardado') }}</div>@endif

    <form wire:submit="guardar" class="card space-y-6" novalidate>
        <div>
            <div class="card-titulo"><span class="paso">1</span>Identificación</div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-campo etiqueta="Tipo de documento" campo="form.tipo_documento" requerido>
                    <select wire:model.live="form.tipo_documento">@foreach ($tiposDocumento as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
                </x-campo>
                <x-campo etiqueta="Número de documento" campo="form.num_documento" requerido
                         :ayuda="match ($form['tipo_documento'] ?? '') { '36' => 'NIT: 14 dígitos (o 9 si es DUI homologado), sin guiones.', '13' => 'DUI: 9 dígitos; se guarda como 00000000-0.', default => 'Hasta 20 caracteres.' }">
                    <input wire:model.blur="form.num_documento" maxlength="20" inputmode="{{ in_array($form['tipo_documento'] ?? '', ['36', '13'], true) ? 'numeric' : 'text' }}" autocomplete="off">
                </x-campo>
                <x-campo etiqueta="NRC" campo="form.nrc" ayuda="Solo contribuyentes de IVA: 2 a 8 dígitos. Habilita el Crédito Fiscal.">
                    <input wire:model.blur="form.nrc" maxlength="10" inputmode="numeric" autocomplete="off">
                </x-campo>
                <x-campo etiqueta="Correo" campo="form.correo" ayuda="Para enviarle sus documentos.">
                    <input type="email" wire:model.blur="form.correo" maxlength="100" placeholder="cliente@empresa.com">
                </x-campo>
                <x-campo etiqueta="Nombre o razón social" campo="form.nombre" requerido class="sm:col-span-2" ayuda="Máximo 250 caracteres, como aparece en su documento.">
                    <input wire:model.blur="form.nombre" maxlength="250">
                </x-campo>
                <x-campo etiqueta="Nombre comercial" campo="form.nombre_comercial" ayuda="Opcional, máximo 150 caracteres.">
                    <input wire:model.blur="form.nombre_comercial" maxlength="150">
                </x-campo>
                <x-campo etiqueta="Teléfono" campo="form.telefono" ayuda="De 8 a 30 caracteres.">
                    <input type="tel" wire:model.blur="form.telefono" maxlength="30" placeholder="2222-3333">
                </x-campo>
            </div>
        </div>

        <div>
            <div class="card-titulo"><span class="paso">2</span>Actividad económica</div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-campo etiqueta="Actividad (catálogo de Hacienda)" campo="form.cod_actividad" :requerido="!empty($form['nrc'])" class="sm:col-span-2" ayuda="Escriba el código o parte del nombre y elija de la lista (CAT-019).">
                    <input wire:model.blur="form.cod_actividad" list="lista-actividades" maxlength="6" placeholder="Ej.: 46900" autocomplete="off">
                    <datalist id="lista-actividades">@foreach ($actividades as $c => $n)<option value="{{ $c }}">{{ $c }} · {{ $n }}</option>@endforeach</datalist>
                </x-campo>
                <x-campo etiqueta="Descripción de la actividad" campo="form.desc_actividad" :requerido="!empty($form['nrc'])" ayuda="Se completa sola al elegir la actividad.">
                    <input wire:model.blur="form.desc_actividad" maxlength="150">
                </x-campo>
            </div>
        </div>

        <div>
            <div class="card-titulo"><span class="paso">3</span>Dirección</div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-campo etiqueta="Departamento" campo="form.departamento" :requerido="!empty($form['nrc'])">
                    <select wire:model.live="form.departamento"><option value="">Elija…</option>@foreach ($departamentos as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
                </x-campo>
                <x-campo etiqueta="Municipio" campo="form.municipio" :requerido="!empty($form['nrc'])">
                    <select wire:model.live="form.municipio" @disabled($municipios === [])><option value="">{{ $municipios === [] ? 'Primero elija el departamento' : 'Elija…' }}</option>@foreach ($municipios as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
                </x-campo>
                <x-campo etiqueta="Distrito" campo="form.distrito" :requerido="!empty($form['nrc'])">
                    <select wire:model.live="form.distrito" @disabled($distritos === [])><option value="">{{ $distritos === [] ? 'Primero elija el departamento' : 'Elija…' }}</option>@foreach ($distritos as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
                </x-campo>
                <x-campo etiqueta="Dirección (complemento)" campo="form.complemento" :requerido="!empty($form['nrc'])" class="sm:col-span-3" ayuda="Colonia, calle, número. Máximo 200 caracteres.">
                    <input wire:model.blur="form.complemento" maxlength="200">
                </x-campo>
            </div>
            <label class="mt-4 flex items-center gap-2"><input type="checkbox" wire:model="form.gran_contribuyente"> Gran contribuyente</label>
        </div>

        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
            @if ($editando)<button type="button" wire:click="limpiar" class="btn-secundario">Cancelar</button>@endif
            <button class="btn-primario">{{ $editando ? 'Guardar cambios' : 'Agregar cliente' }}</button>
        </div>
    </form>

    <div class="card overflow-hidden !p-0">
        <div class="overflow-x-auto"><table>
            <thead><tr><th>Nombre</th><th>Documento</th><th>NRC</th><th>Correo</th><th></th></tr></thead>
            <tbody>
            @forelse ($clientes as $c)
                <tr wire:key="cl-{{ $c->id }}"><td class="font-medium text-tinta">{{ $c->nombre }}</td><td>{{ \App\Livewire\Panel\Clientes::enmascarar($c->num_documento) }}</td><td>{{ $c->nrc ?? '—' }}</td><td>{{ $c->correo ?? '—' }}</td>
                    <td class="text-right"><button wire:click="editar({{ $c->id }})" class="btn-secundario !px-2.5 !py-1 text-xs">Editar</button></td></tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-slate-400">Aún no hay clientes.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    {{ $clientes->links() }}
</div>
