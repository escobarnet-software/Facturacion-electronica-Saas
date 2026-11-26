<div class="space-y-6">
    <div>
        <h1>Emitir documento</h1>
        <p class="mt-1 text-sm text-slate-500">Complete los 3 pasos. La plataforma calcula el IVA y los totales, firma el documento y lo transmite a Hacienda.</p>
    </div>

    @if ($error)<div class="alerta-error" role="alert"><x-icono nombre="alerta" clase="size-5 shrink-0"/>{{ $error }}</div>@endif
    @if ($emitido)<div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/><span>Documento {{ $emitido }} emitido. Lo encuentra en <a class="underline" href="{{ route('panel.documentos') }}">Documentos emitidos</a>.</span></div>@endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card">
                <div class="card-titulo"><span class="paso">1</span>Tipo de documento y cliente</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Tipo de documento
                        <select wire:model.live="tipo" class="mt-1 w-full">
                            <option value="01">Factura (consumidor final)</option>
                            <option value="03">Comprobante de Crédito Fiscal</option>
                        </select>
                    </label>
                    <label class="block">Cliente
                        <select wire:model.live="clienteId" class="mt-1 w-full">
                            <option value="">Consumidor final</option>
                            @foreach ($clientes as $c)<option value="{{ $c->id }}">{{ $c->nombre }}{{ $c->nrc ? ' · NRC '.$c->nrc : '' }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block">Punto de venta
                        <select wire:model.live="puntoVentaId" class="mt-1 w-full">
                            @foreach ($puntosVenta as $pv)<option value="{{ $pv->id }}">{{ $pv->establecimiento->cod_estable_mh }}{{ $pv->cod_punto_venta_mh }} · {{ $pv->nombre }}</option>@endforeach
                        </select>
                    </label>
                    <x-campo etiqueta="Correo para enviar el documento" campo="correo" ayuda="Opcional. Se le envía el PDF y el JSON.">
                        <input type="email" wire:model.blur="correo" maxlength="100" placeholder="cliente@correo.com">
                    </x-campo>
                </div>
                @if ($tipo === '03')
                    <p class="mt-3 text-xs text-slate-500">El Crédito Fiscal exige un cliente con NRC, actividad económica y dirección. Si no aparece, créelo en <a class="underline" href="{{ route('panel.clientes') }}">Clientes</a>.</p>
                @endif
            </section>

            <section class="card">
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <div class="card-titulo !mb-0"><span class="paso">2</span>Productos o servicios</div>
                    <div class="ml-auto flex flex-wrap gap-2">
                        <select class="min-w-56 !py-1.5" wire:change="agregarProducto($event.target.value)" aria-label="Agregar del catálogo">
                            <option value="">+ Agregar del catálogo…</option>
                            @foreach ($productos as $p)<option value="{{ $p->id }}">{{ $p->codigo ? $p->codigo.' · ' : '' }}{{ $p->descripcion }} (${{ \App\Support\Decimal::of((string) $p->precio)->toFixed(2) }} sin IVA)</option>@endforeach
                        </select>
                        <button type="button" wire:click="agregarLinea" class="btn-secundario !py-1.5"><x-icono nombre="mas" clase="size-4"/>Línea libre</button>
                    </div>
                </div>
                <div class="-mx-5 overflow-x-auto">
                    <table>
                        <thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio sin IVA</th>@if ($tipo === '03')<th>Descuento</th><th>Tipo de venta</th>@endif<th></th></tr></thead>
                        <tbody>
                        @forelse ($lineas as $i => $l)
                            <tr wire:key="linea-{{ $i }}">
                                <td><div @class(["campo", "campo-error" => $errors->has("lineas.$i.descripcion")])><input wire:model.live.debounce.400ms="lineas.{{ $i }}.descripcion" class="w-full min-w-48" maxlength="1500" aria-label="Descripción">@error("lineas.$i.descripcion")<p class="mt-1 max-w-48 text-xs font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror</div></td>
                                <td><div @class(["campo", "campo-error" => $errors->has("lineas.$i.cantidad")])><input wire:model.live.debounce.400ms="lineas.{{ $i }}.cantidad" class="w-24" maxlength="20" inputmode="decimal" aria-label="Cantidad">@error("lineas.$i.cantidad")<p class="mt-1 max-w-48 text-xs font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror</div></td>
                                <td><div @class(["campo", "campo-error" => $errors->has("lineas.$i.precio")])><input wire:model.live.debounce.400ms="lineas.{{ $i }}.precio" class="w-32" maxlength="20" inputmode="decimal" aria-label="Precio sin IVA">@error("lineas.$i.precio")<p class="mt-1 max-w-48 text-xs font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror</div></td>
                                @if ($tipo === '03')
                                    <td><div @class(["campo", "campo-error" => $errors->has("lineas.$i.descuento")])><input wire:model.live.debounce.400ms="lineas.{{ $i }}.descuento" class="w-24" maxlength="20" inputmode="decimal" aria-label="Descuento">@error("lineas.$i.descuento")<p class="mt-1 max-w-48 text-xs font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror</div></td>
                                    <td><select wire:model.live="lineas.{{ $i }}.tipoVenta" aria-label="Tipo de venta"><option value="gravada">Gravada</option><option value="exenta">Exenta</option><option value="no_sujeta">No sujeta</option></select></td>
                                @endif
                                <td><button type="button" wire:click="quitarLinea({{ $i }})" class="rounded-lg p-1.5 text-rose-600 hover:bg-rose-50" aria-label="Quitar línea"><x-icono nombre="quitar" clase="size-4"/></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-400">Agregue productos del catálogo o una línea libre.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @error('lineas')<p class="mt-2 text-sm font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror
                <p class="mt-3 text-xs text-slate-500">Hacienda pide: descripción de 1 a 1500 caracteres, cantidad mayor que cero y montos con hasta 8 decimales (sin comas ni signo de dólar).</p>
            </section>

            <section class="card">
                <div class="card-titulo"><span class="paso">3</span>Pago y observaciones</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Condición de la operación
                        <select wire:model="condicionOperacion" class="mt-1 w-full"><option value="1">Contado</option><option value="2">A crédito</option><option value="3">Otro</option></select>
                    </label>
                    <label class="block">Forma de pago
                        <select wire:model="formaPago" class="mt-1 w-full"><option value="01">Billetes y monedas</option><option value="02">Tarjeta débito</option><option value="03">Tarjeta crédito</option><option value="05">Transferencia</option></select>
                    </label>
                    <x-campo etiqueta="Observaciones" campo="observaciones" class="sm:col-span-2" ayuda="Opcional, hasta 3000 caracteres. Aparece en el documento.">
                        <textarea wire:model.blur="observaciones" rows="2" maxlength="3000"></textarea>
                    </x-campo>
                </div>
            </section>
        </div>

        <aside class="lg:col-span-1">
            <div class="card sticky top-24">
                <div class="card-titulo">Resumen</div>
                @if ($vistaPrevia)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Gravado</dt><dd class="font-medium">${{ number_format((float) $vistaPrevia['totalGravada'], 2) }}</dd></div>
                        @if ($tipo === '01')
                            <div class="flex justify-between"><dt class="text-slate-500">IVA incluido</dt><dd class="font-medium">${{ number_format((float) $vistaPrevia['totalIva'], 2) }}</dd></div>
                        @else
                            <div class="flex justify-between"><dt class="text-slate-500">IVA 13 %</dt><dd class="font-medium">${{ number_format((float) ($vistaPrevia['tributos'][0]['valor'] ?? 0), 2) }}</dd></div>
                        @endif
                        <div class="flex items-baseline justify-between border-t border-slate-200 pt-3"><dt class="font-semibold text-tinta">Total a pagar</dt><dd class="text-3xl font-bold tracking-tight text-tinta" data-total>${{ number_format((float) $vistaPrevia['totalPagar'], 2) }}</dd></div>
                    </dl>
                @else
                    <p class="text-sm text-slate-500">Agregue al menos un producto para ver el total.</p>
                @endif
                <button type="button" wire:click="emitir" wire:loading.attr="disabled" class="btn-primario btn-grande mt-5 w-full">
                    <span wire:loading.remove wire:target="emitir"><x-icono nombre="enviar" clase="inline size-5"/> Emitir y enviar a Hacienda</span>
                    <span wire:loading wire:target="emitir">Emitiendo…</span>
                </button>
                <ul class="mt-4 space-y-1.5 text-xs text-slate-500">
                    <li class="flex gap-2"><x-icono nombre="ok" clase="size-4 shrink-0 text-emerald-600"/>Validado contra el esquema oficial antes de firmar.</li>
                    <li class="flex gap-2"><x-icono nombre="ok" clase="size-4 shrink-0 text-emerald-600"/>Si Hacienda no responde, se entrega igual (contingencia).</li>
                </ul>
            </div>
        </aside>
    </div>
</div>
