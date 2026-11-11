<div class="space-y-6">
    <h1 class="text-2xl font-semibold">Emitir documento</h1>

    @if ($error)<div class="rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">{{ $error }}</div>@endif
    @if ($emitido)<div class="rounded border border-green-300 bg-green-50 p-3 text-green-800" role="status">Documento {{ $emitido }} emitido.</div>@endif

    <div class="grid gap-4 rounded border bg-white p-4 sm:grid-cols-3">
        <label class="block">Tipo
            <select wire:model.live="tipo" class="mt-1 w-full rounded border p-2">
                <option value="01">Factura</option>
                <option value="03">Comprobante de Crédito Fiscal</option>
            </select>
        </label>
        <label class="block">Cliente
            <select wire:model.live="clienteId" class="mt-1 w-full rounded border p-2">
                <option value="">Consumidor final</option>
                @foreach ($clientes as $c)<option value="{{ $c->id }}">{{ $c->nombre }}{{ $c->nrc ? ' · NRC '.$c->nrc : '' }}</option>@endforeach
            </select>
        </label>
        <label class="block">Punto de venta
            <select wire:model.live="puntoVentaId" class="mt-1 w-full rounded border p-2">
                @foreach ($puntosVenta as $pv)<option value="{{ $pv->id }}">{{ $pv->establecimiento->cod_estable_mh }}{{ $pv->cod_punto_venta_mh }} · {{ $pv->nombre }}</option>@endforeach
            </select>
        </label>
        <label class="block">Correo para la entrega
            <input type="email" wire:model="correo" class="mt-1 w-full rounded border p-2" placeholder="opcional">
        </label>
        <label class="block">Condición
            <select wire:model="condicionOperacion" class="mt-1 w-full rounded border p-2"><option value="1">Contado</option><option value="2">A crédito</option><option value="3">Otro</option></select>
        </label>
        <label class="block">Forma de pago
            <select wire:model="formaPago" class="mt-1 w-full rounded border p-2"><option value="01">Billetes y monedas</option><option value="02">Tarjeta débito</option><option value="03">Tarjeta crédito</option><option value="05">Transferencia</option></select>
        </label>
    </div>

    <div class="rounded border bg-white p-4">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="font-semibold">Detalle</h2>
            <select class="rounded border p-1 text-sm" wire:change="agregarProducto($event.target.value)">
                <option value="">Agregar del catálogo…</option>
                @foreach ($productos as $p)<option value="{{ $p->id }}">{{ $p->codigo }} {{ $p->descripcion }} (${{ number_format((float) $p->precio, 2) }} sin IVA)</option>@endforeach
            </select>
            <button type="button" wire:click="agregarLinea" class="rounded border px-2 py-1 text-sm">Línea libre</button>
        </div>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500"><th>Descripción</th><th>Cantidad</th><th>Precio sin IVA</th>@if ($tipo === '03')<th>Descuento</th><th>Tipo de venta</th>@endif<th></th></tr></thead>
            <tbody>
            @foreach ($lineas as $i => $l)
                <tr wire:key="linea-{{ $i }}">
                    <td><input wire:model.live.debounce.400ms="lineas.{{ $i }}.descripcion" class="w-full rounded border p-1"></td>
                    <td><input wire:model.live.debounce.400ms="lineas.{{ $i }}.cantidad" class="w-24 rounded border p-1" inputmode="decimal"></td>
                    <td><input wire:model.live.debounce.400ms="lineas.{{ $i }}.precio" class="w-28 rounded border p-1" inputmode="decimal"></td>
                    @if ($tipo === '03')
                        <td><input wire:model.live.debounce.400ms="lineas.{{ $i }}.descuento" class="w-24 rounded border p-1" inputmode="decimal"></td>
                        <td><select wire:model.live="lineas.{{ $i }}.tipoVenta" class="rounded border p-1"><option value="gravada">Gravada</option><option value="exenta">Exenta</option><option value="no_sujeta">No sujeta</option></select></td>
                    @endif
                    <td><button type="button" wire:click="quitarLinea({{ $i }})" class="text-red-700" aria-label="Quitar línea">✕</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @error('lineas.*')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">Observaciones<textarea wire:model="observaciones" class="mt-1 w-full rounded border p-2" rows="3"></textarea></label>
        <div class="rounded border bg-white p-4">
            <h2 class="mb-2 font-semibold">Resumen</h2>
            @if ($vistaPrevia)
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt>Gravado</dt><dd class="text-right">${{ number_format((float) $vistaPrevia['totalGravada'], 2) }}</dd>
                    @if ($tipo === '01')<dt>IVA incluido</dt><dd class="text-right">${{ number_format((float) $vistaPrevia['totalIva'], 2) }}</dd>
                    @else<dt>IVA 13 %</dt><dd class="text-right">${{ number_format((float) ($vistaPrevia['tributos'][0]['valor'] ?? 0), 2) }}</dd>@endif
                    <dt class="font-semibold">Total a pagar</dt><dd class="text-right font-semibold" data-total>${{ number_format((float) $vistaPrevia['totalPagar'], 2) }}</dd>
                </dl>
            @else
                <p class="text-sm text-slate-500">Complete los datos para ver el resumen.</p>
            @endif
            <button type="button" wire:click="emitir" wire:loading.attr="disabled" class="mt-4 w-full rounded bg-blue-700 py-2 text-white">Emitir</button>
        </div>
    </div>
</div>
