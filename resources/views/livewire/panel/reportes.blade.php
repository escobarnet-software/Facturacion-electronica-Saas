<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Libros de IVA</h1>
    <label class="block text-sm">Período <input type="month" wire:model.live="periodo" class="rounded border p-1"></label>

    <div class="rounded border bg-white p-3">
        <h2 class="mb-2 font-semibold">Descargas del {{ \Illuminate\Support\Carbon::parse($desde)->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($hasta)->format('d/m/Y') }}</h2>
        <ul class="space-y-1 text-sm">
            @foreach ($libros as $clave => $nombre)
                <li>{{ $nombre }}:
                    <a class="underline" href="{{ route('panel.reportes.descargar', ['libro' => $clave, 'formato' => 'xlsx', 'desde' => $desde, 'hasta' => $hasta]) }}">Excel</a> ·
                    <a class="underline" href="{{ route('panel.reportes.descargar', ['libro' => $clave, 'formato' => 'csv', 'desde' => $desde, 'hasta' => $hasta]) }}">CSV</a>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded border bg-white p-3 text-sm">
            <h2 class="mb-2 font-semibold">Resumen para la declaración (F-07)</h2>
            <dl class="grid grid-cols-2 gap-1">
                @foreach ([
                    'ventas_gravadas_consumidor_base' => 'Ventas gravadas a consumidor final (sin IVA)', 'debito_fiscal_consumidor' => 'Débito fiscal consumidor final',
                    'ventas_gravadas_contribuyentes' => 'Ventas gravadas a contribuyentes', 'debito_fiscal_contribuyentes' => 'Débito fiscal contribuyentes',
                    'ventas_exentas_y_no_sujetas' => 'Ventas exentas y no sujetas', 'exportaciones' => 'Exportaciones',
                    'total_debito_fiscal' => 'Total débito fiscal', 'compras_gravadas' => 'Compras gravadas', 'credito_fiscal' => 'Crédito fiscal',
                    'compras_sujetos_excluidos' => 'Compras a sujetos excluidos', 'percepciones_sufridas' => 'IVA percibido por terceros',
                    'retenciones_sufridas' => 'IVA retenido por terceros', 'impuesto_a_pagar' => 'Impuesto a pagar', 'remanente_credito' => 'Remanente de crédito fiscal',
                ] as $k => $etiqueta)
                    <dt>{{ $etiqueta }}</dt><dd class="text-right font-mono">${{ $f07[$k] }}</dd>
                @endforeach
            </dl>
        </div>
        <div class="rounded border bg-white p-3 text-sm">
            <h2 class="mb-2 font-semibold">Retenciones y percepciones</h2>
            <dl class="grid grid-cols-2 gap-1">
                <dt>Retenciones efectuadas (CR emitidos)</dt><dd class="text-right font-mono">${{ $retenciones['retenciones_efectuadas'] }}</dd>
                <dt>Retenciones sufridas</dt><dd class="text-right font-mono">${{ $retenciones['retenciones_sufridas'] }}</dd>
                <dt>Percepciones efectuadas</dt><dd class="text-right font-mono">${{ $retenciones['percepciones_efectuadas'] }}</dd>
                <dt>Percepciones sufridas</dt><dd class="text-right font-mono">${{ $retenciones['percepciones_sufridas'] }}</dd>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Valores de apoyo: revise con su contador antes de declarar. El formato de los anexos se ajustará al instructivo vigente de Hacienda.</p>
        </div>
    </div>
</div>
