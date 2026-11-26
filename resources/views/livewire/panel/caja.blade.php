<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1>Caja rápida</h1>
            <p class="mt-1 text-sm text-slate-500">Escanee o toque los productos y cobre. Se emite una Factura a consumidor final y se transmite a Hacienda al instante.</p>
        </div>
    </div>

    @if ($error)<div class="alerta-error" role="alert"><x-icono nombre="alerta" clase="size-5 shrink-0"/>{{ $error }}</div>@endif
    @if ($mensaje)
        <div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>
            <div class="flex flex-1 flex-wrap items-center justify-between gap-2">
                <span>{{ $mensaje }}</span>
                @if ($ultimo)<a class="btn-secundario" target="_blank" href="{{ route('panel.documentos.pdf', ['dte' => $ultimo->id, 'formato' => 'ticket']) }}"><x-icono nombre="descargar" clase="size-4"/>Imprimir ticket</a>@endif
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <div class="card">
                <form wire:submit="escanear" class="flex gap-2">
                    <input wire:model="codigo" autofocus class="min-w-0 flex-1 !py-3 !text-lg" placeholder="Escanee o escriba el código" aria-label="Código del producto">
                    <button class="btn-primario"><x-icono nombre="mas" clase="size-4"/>Agregar</button>
                </form>
                @if ($rapidos->isNotEmpty())
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($rapidos as $p)
                            <button type="button" wire:click="agregarProducto({{ $p->id }})" wire:key="r-{{ $p->id }}"
                                    class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-left transition hover:border-marca-300 hover:bg-marca-50">
                                <div class="truncate text-sm font-semibold text-tinta">{{ $p->descripcion }}</div>
                                <div class="mt-0.5 flex items-center justify-between text-xs text-slate-500">
                                    <span>{{ $p->codigo ?? 'Sin código' }}</span>
                                    <span class="font-semibold text-marca-700">${{ \App\Support\Decimal::of((string) $p->precio)->mul('1.13')->toFixed(2) }}</span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="card overflow-hidden !p-0">
                <table>
                    <thead><tr><th>Producto</th><th class="w-28">Cantidad</th><th class="text-right">Precio</th><th class="w-12"></th></tr></thead>
                    <tbody>
                    @forelse ($carrito as $i => $l)
                        <tr wire:key="c-{{ $i }}">
                            <td class="font-medium">{{ $l['descripcion'] }}</td>
                            <td><input wire:model.live.debounce.300ms="carrito.{{ $i }}.cantidad" class="w-20 text-center" inputmode="decimal" aria-label="Cantidad de {{ $l['descripcion'] }}"></td>
                            <td class="text-right">${{ preg_match('/^\d{1,11}(\.\d{1,8})?$/', (string) $l['precio']) ? \App\Support\Decimal::of((string) $l['precio'])->mul('1.13')->toFixed(2) : '0.00' }}</td>
                            <td><button wire:click="quitar({{ $i }})" class="rounded-lg p-1.5 text-rose-600 hover:bg-rose-50" aria-label="Quitar {{ $l['descripcion'] }}"><x-icono nombre="quitar" clase="size-4"/></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-slate-400">Agregue productos para empezar la venta.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div class="card sticky top-24 space-y-5">
                <div>
                    <div class="kpi-etiqueta">Total a cobrar (IVA incluido)</div>
                    <div class="mt-1 text-5xl font-bold tracking-tight text-tinta">${{ $total }}</div>
                </div>
                <label class="block">Forma de pago
                    <select wire:model="formaPago" class="mt-1 w-full"><option value="01">Efectivo</option><option value="02">Tarjeta débito</option><option value="03">Tarjeta crédito</option></select>
                </label>
                <button wire:click="cobrar" wire:loading.attr="disabled" class="btn-exito btn-grande w-full" @disabled($carrito === [])>
                    <span wire:loading.remove wire:target="cobrar"><x-icono nombre="ok" clase="inline size-5"/> Cobrar ${{ $total }}</span>
                    <span wire:loading wire:target="cobrar">Emitiendo…</span>
                </button>
                <p class="text-xs text-slate-500">Si Hacienda no responde, la factura se entrega igual y se transmite sola al volver el servicio (contingencia).</p>
            </div>
        </div>
    </div>
</div>
