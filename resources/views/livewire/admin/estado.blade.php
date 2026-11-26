<div class="space-y-4" wire:poll.60s>
    <h1>Estado de la plataforma</h1>

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Hacienda (últimas 24 horas)</h2>
        <div class="overflow-x-auto"><table class="w-full">
            <thead><tr class="bg-slate-100 text-left"><th class="p-1">Ambiente</th><th>Servicio</th><th class="text-right">Llamadas</th><th class="text-right">Errores</th><th class="text-right">Promedio</th><th class="text-right">Máximo</th></tr></thead>
            <tbody>
            @forelse ($endpoints as $e)
                <tr class="border-t {{ $e->errores > 0 ? 'text-red-800' : '' }}">
                    <td class="p-1">{{ $e->ambiente === '01' ? 'Producción' : 'Pruebas' }}</td><td class="font-mono">{{ $e->endpoint }}</td>
                    <td class="text-right">{{ $e->llamadas }}</td><td class="text-right">{{ $e->errores }}</td>
                    <td class="text-right">{{ $e->promedio_ms !== null ? $e->promedio_ms.' ms' : '—' }}</td><td class="text-right">{{ $e->max_ms !== null ? $e->max_ms.' ms' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-1 text-slate-500">Sin llamadas en las últimas 24 horas.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Correo</h2>
        <p>Servidor: <span class="font-mono">{{ $correo['servidor'] }}</span> · Remitente: <span class="font-mono">{{ $correo['remitente'] }}</span>
            @if ($correo['prueba'])<span class="insignia-ambar ml-2">Buzón de prueba (no sale a internet)</span>@else<span class="insignia-verde ml-2">Correo real</span>@endif</p>
        <button wire:click="probarCorreo" class="btn-secundario mt-3">Enviarme un correo de prueba</button>
        @if ($resultadoCorreo)<p class="mt-2" role="status">{{ $resultadoCorreo }}</p>@endif
    </section>

    <div class="grid gap-4 md:grid-cols-3">
        <section class="card text-sm">
            <h2 class="mb-2 font-semibold">Colas</h2>
            <dl class="grid grid-cols-2 gap-1">
                @foreach ($colas as $cola => $tamano)<dt>{{ $cola }}</dt><dd class="text-right">{{ $tamano ?? 'sin conexión' }}</dd>@endforeach
            </dl>
        </section>
        <section class="card text-sm">
            <h2 class="mb-2 font-semibold">Contingencias activas</h2>
            <p class="text-3xl">{{ $contingencias }}</p>
        </section>
        <section class="card text-sm">
            <h2 class="mb-2 font-semibold">Rechazos más comunes</h2>
            <ul>
                @forelse ($rechazos as $r)
                    <li>{{ $r->codigo ?? 'sin código' }} — {{ \App\Dte\Hacienda\MensajesHacienda::traducir($r->codigo, null) }} ({{ $r->total }})</li>
                @empty
                    <li class="text-slate-500">Sin rechazos.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="card text-sm">
        <h2 class="mb-2 font-semibold">Documentos por día (14 días)</h2>
        <div class="overflow-x-auto"><table class="w-full">
            @forelse ($porDia as $dia => $total)
                <tr class="border-t"><td class="p-1">{{ \Illuminate\Support\Carbon::parse($dia)->format('d/m/Y') }}</td><td class="text-right">{{ $total }}</td></tr>
            @empty
                <tr><td class="p-1 text-slate-500">Sin documentos.</td></tr>
            @endforelse
        </table></div>
    </section>
</div>
