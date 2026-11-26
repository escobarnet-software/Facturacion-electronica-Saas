<div class="space-y-6">
    <div>
        <h1>{{ $tipo === '05' ? 'Nota de Crédito' : 'Nota de Débito' }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            @if ($tipo === '05')
                Para devoluciones, descuentos posteriores o corregir un precio cobrado de más en el Crédito Fiscal. Reduce el IVA de la venta.
            @else
                Para cobrar una diferencia que faltó en el Crédito Fiscal (precio cobrado de menos, intereses, cargos). Aumenta el IVA de la venta.
            @endif
        </p>
    </div>

    @if ($error)<div class="alerta-error" role="alert"><x-icono nombre="alerta" clase="size-5 shrink-0"/>{{ $error }}</div>@endif
    @if ($emitido)
        <div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>
            <span>{{ $tipo === '05' ? 'Nota de Crédito' : 'Nota de Débito' }} {{ $emitido }} emitida. Lo ve en <a class="underline" href="{{ route('panel.documentos') }}">Documentos emitidos</a>.</span>
        </div>
    @endif

    <div class="card flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
        <div><div class="kpi-etiqueta">Crédito Fiscal que ajusta</div><div class="font-mono text-xs text-tinta">{{ $ccf->numero_control }}</div></div>
        <div><div class="kpi-etiqueta">Cliente</div><div class="font-medium text-tinta">{{ $ccf->receptor_nombre }}</div></div>
        <div><div class="kpi-etiqueta">Fecha</div><div>{{ $ccf->fecha_emision->format('d/m/Y') }}</div></div>
        <div><div class="kpi-etiqueta">Total del Crédito Fiscal</div><div class="font-semibold">${{ \App\Support\Decimal::of((string) $ccf->getRawOriginal('total_pagar'))->toFixed(2) }}</div></div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card">
                <div class="card-titulo"><span class="paso">1</span>Qué se {{ $tipo === '05' ? 'acredita' : 'cobra' }}</div>
                <p class="mb-3 text-xs text-slate-500">Se cargaron las líneas del Crédito Fiscal. Deje solo las que ajusta y cambie la cantidad o el precio por la diferencia; quite las demás.</p>
                <div class="-mx-5 overflow-x-auto">
                    <table>
                        <thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio sin IVA</th><th>Descuento</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($lineas as $i => $l)
                            <tr wire:key="aj-{{ $i }}">
                                @foreach (['descripcion' => 'w-full min-w-48', 'cantidad' => 'w-24', 'precio' => 'w-32', 'descuento' => 'w-24'] as $campo => $clase)
                                    <td><div @class(['campo', 'campo-error' => $errors->has("lineas.$i.$campo")])>
                                        <input wire:model.live.debounce.400ms="lineas.{{ $i }}.{{ $campo }}" class="{{ $clase }}" maxlength="{{ $campo === 'descripcion' ? 1500 : 20 }}" @if ($campo !== 'descripcion') inputmode="decimal" @endif aria-label="{{ $campo }}">
                                        @error("lineas.$i.$campo")<p class="mt-1 max-w-48 text-xs font-medium text-rose-700">{{ $message }}</p>@enderror
                                    </div></td>
                                @endforeach
                                <td><button type="button" wire:click="quitarLinea({{ $i }})" class="rounded-lg p-1.5 text-rose-600 hover:bg-rose-50" aria-label="Quitar línea"><x-icono nombre="quitar" clase="size-4"/></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-slate-400">No quedan líneas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @error('lineas')<p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>@enderror
            </section>
            <section class="card">
                <div class="card-titulo"><span class="paso">2</span>Motivo</div>
                <x-campo etiqueta="Motivo" campo="motivo" requerido ayuda="Aparece en el documento. Ej.: «Devolución de 2 unidades por daño».">
                    <textarea wire:model.blur="motivo" rows="2" maxlength="3000"></textarea>
                </x-campo>
            </section>
        </div>
        <aside>
            <div class="card sticky top-24">
                <div class="card-titulo">Resumen</div>
                @if ($resumen)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Gravado</dt><dd>${{ number_format((float) ($resumen['totalGravada'] ?? 0), 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">IVA 13 %</dt><dd>${{ number_format((float) ($resumen['tributos'][0]['valor'] ?? 0), 2) }}</dd></div>
                        <div class="flex items-baseline justify-between border-t border-slate-200 pt-3"><dt class="font-semibold text-tinta">Total</dt><dd class="text-3xl font-bold text-tinta">${{ number_format((float) ($resumen['montoTotalOperacion'] ?? $resumen['totalPagar'] ?? 0), 2) }}</dd></div>
                    </dl>
                @else
                    <p class="text-sm text-slate-500">Revise las líneas para ver el total.</p>
                @endif
                <button type="button" wire:click="emitir" wire:loading.attr="disabled" class="btn-primario btn-grande mt-5 w-full" @disabled($emitido)>
                    <span wire:loading.remove wire:target="emitir"><x-icono nombre="enviar" clase="inline size-5"/> Emitir {{ $tipo === '05' ? 'Nota de Crédito' : 'Nota de Débito' }}</span>
                    <span wire:loading wire:target="emitir">Emitiendo…</span>
                </button>
                <a href="{{ route('panel.documentos') }}" class="btn-secundario mt-2 w-full">Volver a documentos</a>
            </div>
        </aside>
    </div>
</div>
