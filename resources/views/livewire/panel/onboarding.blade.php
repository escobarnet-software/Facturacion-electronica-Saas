<div class="space-y-6" @if ($pruebas->contains('estado', 'en_curso')) wire:poll.5s @endif>
    <h1 class="text-2xl font-semibold">Configuración y autorización como emisor</h1>
    @if ($error)<div class="rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">{{ $error }}</div>@endif
    @if ($mensaje)<div class="rounded border border-green-300 bg-green-50 p-3 text-green-800" role="status">{{ $mensaje }}</div>@endif
    <p class="text-sm text-slate-600">Ambiente actual: <strong>{{ $modelo->ambiente === '01' ? 'Producción' : 'Pruebas (sin valor legal)' }}</strong>.
        Escobar Net nunca le pedirá contraseñas por chat ni WhatsApp: cárguelas usted mismo aquí.</p>

    <section class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">1. Datos de la empresa</h2>
        <form wire:submit="guardarEmpresa" class="grid gap-2 sm:grid-cols-3">
            <label>NRC<input wire:model="empresa.nrc" class="mt-1 w-full rounded border p-2"></label>
            <label>Razón social<input wire:model="empresa.razon_social" class="mt-1 w-full rounded border p-2"></label>
            <label>Nombre comercial<input wire:model="empresa.nombre_comercial" class="mt-1 w-full rounded border p-2"></label>
            <label>Código de actividad (CAT-019)<input wire:model="empresa.cod_actividad" class="mt-1 w-full rounded border p-2"></label>
            <label class="sm:col-span-2">Actividad económica<input wire:model="empresa.desc_actividad" class="mt-1 w-full rounded border p-2"></label>
            <label>Teléfono<input wire:model="empresa.telefono" class="mt-1 w-full rounded border p-2"></label>
            <label>Correo<input wire:model="empresa.correo" class="mt-1 w-full rounded border p-2"></label>
            <label>Tipo de contribuyente<select wire:model="empresa.tipo_contribuyente" class="mt-1 w-full rounded border p-2"><option value="grande">Grande</option><option value="mediano">Mediano</option><option value="otro">Otro</option></select></label>
            <label>Departamento<input wire:model="empresa.departamento" class="mt-1 w-full rounded border p-2"></label>
            <label>Municipio<input wire:model="empresa.municipio" class="mt-1 w-full rounded border p-2"></label>
            <label>Distrito<input wire:model="empresa.distrito" class="mt-1 w-full rounded border p-2"></label>
            <label class="sm:col-span-3">Dirección<input wire:model="empresa.complemento" class="mt-1 w-full rounded border p-2"></label>
            <label>Responsable ante Hacienda<input wire:model="empresa.responsable_nombre" class="mt-1 w-full rounded border p-2"></label>
            <label>Tipo de documento<select wire:model="empresa.responsable_tipo_doc" class="mt-1 w-full rounded border p-2"><option value="13">DUI</option><option value="36">NIT</option><option value="03">Pasaporte</option></select></label>
            <label>Número de documento<input wire:model="empresa.responsable_num_doc" class="mt-1 w-full rounded border p-2"></label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="empresa.agente_retencion"> Agente de retención</label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="empresa.agente_percepcion"> Agente de percepción</label>
            @if ($errors->hasAny(array_map(fn ($k) => 'empresa.'.$k, array_keys($empresa))))<p class="text-sm text-red-700 sm:col-span-3">{{ $errors->first() }}</p>@endif
            <button class="rounded bg-blue-700 py-2 text-white sm:col-span-3">Guardar datos</button>
        </form>
    </section>

    <section class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">2. Establecimiento y punto de venta</h2>
        <p>{{ $tienePuntoVenta ? '✔ Ya tiene al menos un punto de venta.' : 'Cree su casa matriz y su punto de venta con los códigos que le asignó Hacienda.' }}
            <a class="underline" href="{{ route('panel.establecimientos') }}">Establecimientos</a></p>
    </section>

    <section class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">3. Credenciales de Hacienda</h2>
        <ol class="mb-3 list-inside list-decimal text-sm text-slate-600">
            <li>En factura.gob.sv solicite el ingreso al ambiente de pruebas.</li>
            <li>En el Sitio de Emisores genere su certificado y su usuario de la API (contraseña de 13 a 25 caracteres, con mayúscula y carácter especial, sin espacios).</li>
            <li>Cárguelos aquí. Se guardan cifrados y nunca se vuelven a mostrar.</li>
        </ol>
        <ul class="mb-3 text-sm">
            @forelse ($credenciales as $c)
                <li>Ambiente {{ $c['ambiente'] }}: certificado cargado el {{ $c['certificado_cargado_at'] ? \Carbon\Carbon::parse($c['certificado_cargado_at'])->format('d/m/Y') : '—' }}{{ $c['bloqueado'] ? ' · BLOQUEADAS: cárguelas de nuevo' : '' }}</li>
            @empty
                <li class="text-slate-500">Aún no hay credenciales.</li>
            @endforelse
        </ul>
        <form wire:submit="guardarCredenciales" class="grid gap-2 sm:grid-cols-4">
            <label>Ambiente<select wire:model="ambienteCredencial" class="mt-1 w-full rounded border p-2"><option value="00">Pruebas</option><option value="01">Producción</option></select></label>
            <label>Certificado (.crt)<input type="file" wire:model="certificado" class="mt-1 w-full text-sm"></label>
            <label>Contraseña de la API<input type="password" wire:model="pwdApi" autocomplete="new-password" class="mt-1 w-full rounded border p-2"></label>
            <label>Contraseña de la llave privada<input type="password" wire:model="pwdPrivada" autocomplete="new-password" class="mt-1 w-full rounded border p-2"></label>
            @error('pwdApi')<p class="text-sm text-red-700 sm:col-span-4">{{ $message }}</p>@enderror
            @error('certificado')<p class="text-sm text-red-700 sm:col-span-4">{{ $message }}</p>@enderror
            <button class="rounded bg-blue-700 py-2 text-white sm:col-span-4">Guardar credenciales</button>
        </form>
    </section>

    <section class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">4. Pruebas mínimas (ambiente de pruebas)</h2>
        <div class="mb-2 flex flex-wrap gap-3 text-sm">
            @foreach ($tiposDisponibles as $tipo => $n)<label class="flex items-center gap-1"><input type="checkbox" value="{{ $tipo }}" wire:model="tiposPrueba"> {{ $tipo }} ({{ $n }})</label>@endforeach
        </div>
        <button wire:click="ejecutarPruebas" class="rounded bg-slate-700 px-4 py-2 text-white">Ejecutar o reanudar</button>
        <div class="mt-3 space-y-2">
            @foreach ($pruebas as $p)
                <div><div class="flex justify-between text-sm"><span>{{ $p->tipo }} · {{ $p->estado }}</span><span>{{ $p->exitosas }}/{{ $p->requeridas }}</span></div>
                    <div class="h-2 rounded bg-slate-200"><div class="h-2 rounded bg-green-600" style="width: {{ $p->porcentaje() }}%"></div></div>
                    @if ($p->ultimo_error && $p->estado !== 'completada')<p class="text-xs text-red-700">{{ $p->ultimo_error }}</p>@endif</div>
            @endforeach
        </div>
    </section>

    <section class="rounded border bg-white p-4">
        <h2 class="mb-2 font-semibold">5. Autorización y paso a producción</h2>
        <p class="mb-2 text-sm text-slate-600">En el portal de Hacienda (pantalla Inicio) verifique las barras de avance y solicite la autorización. Una vez autorizado, genere el certificado y el usuario de la API de PRODUCCIÓN, cárguelos arriba (ambiente Producción) y pase a producción.</p>
        <button wire:click="pasarAProduccion" wire:confirm="Desde ahora sus documentos tendrán validez legal. ¿Continuar?" class="rounded bg-red-700 px-4 py-2 text-white" @disabled($modelo->ambiente === '01')>Pasar a producción</button>
    </section>
</div>
