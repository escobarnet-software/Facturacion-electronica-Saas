<div class="space-y-4">
    <h1 >Invalidaciones</h1>
    <p class="-mt-2 text-sm text-slate-500">Anule un documento sellado dentro del plazo legal. Hacienda registra el evento de invalidación.</p>
    @if ($error)<div class="alerta-error" role="alert">{{ $error }}</div>@endif
    @if ($mensaje)<div class="alerta-exito" role="status">{{ $mensaje }}</div>@endif

    @if ($seleccionado)
        <div class="space-y-2 card">
            <h2 class="font-semibold">Invalidar {{ $seleccionado->numero_control }}</h2>
            <p class="text-sm text-slate-600">Un error de datos se invalida. Si cambió la cantidad o el precio de un Crédito Fiscal, use una Nota de Crédito o Débito.</p>
            <label class="block">Tipo de invalidación
                <select wire:model.live="tipoAnulacion" class="mt-1 w-full ">
                    <option value="1">Error en la información (requiere el documento que lo reemplaza)</option>
                    <option value="2">Rescindir la operación</option>
                    <option value="3">Otro (requiere motivo y documento que reemplaza)</option>
                </select>
            </label>
            @if ($tipoAnulacion !== 2)
                <x-campo etiqueta="Código de generación del documento que reemplaza" campo="codigoReemplazo" requerido ayuda="36 caracteres; el documento nuevo ya debe estar sellado."><input wire:model="codigoReemplazo" maxlength="36" class="font-mono uppercase"></x-campo>
            @endif
            <x-campo etiqueta="Motivo" campo="motivo" :requerido="$tipoAnulacion === 3" ayuda="Hasta 200 caracteres."><input wire:model="motivo" maxlength="200"></x-campo>
            <div class="grid gap-2 sm:grid-cols-3">
                <x-campo etiqueta="Nombre de quien solicita" campo="nombreSolicita" requerido ayuda="Hasta 100 caracteres."><input wire:model="nombreSolicita" maxlength="100"></x-campo>
                <label class="block">Tipo de documento<select wire:model.live="tipDocSolicita" class="mt-1 w-full"><option value="13">DUI</option><option value="36">NIT</option><option value="03">Pasaporte</option><option value="02">Carnet de residente</option><option value="37">Otro</option></select></label>
                <x-campo etiqueta="Número de documento" campo="numDocSolicita" requerido :ayuda="$tipDocSolicita === '13' ? 'DUI: 9 dígitos; se guarda como 00000000-0.' : ($tipDocSolicita === '36' ? 'NIT: 9 o 14 dígitos, sin guiones.' : 'Hasta 20 caracteres.')"><input wire:model="numDocSolicita" maxlength="20"></x-campo>
            </div>
            <button wire:click="invalidar" wire:loading.attr="disabled" class="rounded bg-red-700 px-4 py-2 text-white">Invalidar</button>
        </div>
    @endif

    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="bg-slate-100 text-left"><th class="p-2">Número de control</th><th>Tipo</th><th>Fecha</th><th>Plazo hasta</th><th>Quedan</th><th></th></tr></thead>
        <tbody>
        @foreach ($documentos as $f)
            <tr class="border-t {{ $f['vencido'] ? 'text-slate-400' : ($f['dias'] <= 3 ? 'bg-amber-50' : '') }}">
                <td class="p-2 font-mono text-xs">{{ $f['dte']->numero_control }}</td><td>{{ $f['dte']->tipo }}</td><td>{{ $f['dte']->fecha_emision->format('d/m/Y') }}</td>
                <td>{{ $f['limite']->format('d/m/Y') }}</td><td>{{ $f['vencido'] ? 'Vencido' : $f['dias'].' día(s)' }}</td>
                <td>@unless ($f['vencido'])<button wire:click="$set('dteId', {{ $f['dte']->id }})" class="underline">Invalidar</button>@endunless</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
