<div class="space-y-4">
    <h1 >Plan y soporte</h1>
    <p class="-mt-2 text-sm text-slate-500">Su plan, el consumo del mes y el acceso temporal del soporte de Escobar Net.</p>

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Su plan</h2>
        @if ($plan)
            <p><strong>{{ $plan->nombre }}</strong> · ${{ $plan->getRawOriginal('precio_mensual') }} al mes (más IVA) ·
                {{ $plan->documentos_mes === null ? 'documentos ilimitados' : $plan->documentos_mes.' documentos al mes' }}</p>
            <p class="mt-1">Este mes: {{ $consumo?->documentos ?? 0 }} documento(s)
                @if ($consumo && $consumo->excedente() > 0) · <span class="text-amber-700">{{ $consumo->excedente() }} adicional(es), se incluirán en su próximo cobro</span>@endif
            </p>
            <p class="mt-1 text-slate-500">La emisión nunca se detiene por el plan: emitir sus documentos es una obligación legal.</p>
        @else
            <p class="text-slate-600">Su empresa aún no tiene un plan asignado. Comuníquese con Escobar Net.</p>
        @endif
    </section>

    @if ($cobros->isNotEmpty())
        <section class="card text-sm">
            <h2 class="mb-2 font-semibold">Cobros</h2>
            <div class="overflow-x-auto"><table class="w-full">
                <thead><tr class="bg-slate-100 text-left"><th class="p-1">Período</th><th>Documentos</th><th>Adicionales</th><th class="text-right">Subtotal (sin IVA)</th><th>Estado</th></tr></thead>
                <tbody>
                @foreach ($cobros as $c)
                    <tr class="border-t"><td class="p-1">{{ $c->periodo }}</td><td>{{ $c->documentos }}</td><td>{{ $c->excedente }}</td><td class="text-right">${{ $c->getRawOriginal('subtotal') }}</td><td>{{ ucfirst($c->estado) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Acceso del soporte de Escobar Net</h2>
        <p class="mb-2 text-slate-600">El soporte solo puede ver sus documentos mientras usted le dé permiso, y nunca ve sus credenciales de Hacienda.</p>
        <form wire:submit="otorgar" class="flex flex-wrap items-end gap-2">
            <label>Horas <input type="number" wire:model="horas" min="1" max="{{ config('plataforma.soporte_horas_max') }}" class="w-20 "></label>
            <input wire:model="motivo" class="flex-1 " placeholder="Motivo (por ejemplo: revisar un rechazo)" aria-label="Motivo">
            <button class="btn-primario">Dar permiso</button>
        </form>
        @error('horas')<p class="text-red-700">{{ $message }}</p>@enderror
        @error('motivo')<p class="text-red-700">{{ $message }}</p>@enderror
        <ul class="mt-2 space-y-1">
            @foreach ($accesos as $a)
                <li wire:key="a-{{ $a->id }}">{{ $a->motivo }} — hasta {{ $a->expira_at->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}
                    @if ($a->vigente())<button wire:click="revocar({{ $a->id }})" class="underline text-red-700">Revocar</button>@else<span class="text-slate-500">(sin efecto)</span>@endif</li>
            @endforeach
        </ul>
    </section>
</div>
