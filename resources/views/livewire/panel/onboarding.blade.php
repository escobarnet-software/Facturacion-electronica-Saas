<div class="space-y-6" @if ($pruebas->contains('estado', 'en_curso')) wire:poll.5s @endif>
    @php
        $nombresTipo = ['01' => 'Factura', '03' => 'Crédito Fiscal', '04' => 'Nota de remisión', '05' => 'Nota de crédito', '06' => 'Nota de débito',
            '07' => 'Comprobante de retención', '08' => 'Comprobante de liquidación', '09' => 'Documento contable de liquidación',
            '11' => 'Factura de exportación', '14' => 'Sujeto excluido', '15' => 'Comprobante de donación',
            'invalidacion' => 'Anulaciones', 'contingencia' => 'Contingencias', 'retorno' => 'Retornos', 'operaciones_especiales' => 'Operaciones especiales'];
        $pasos = [
            ['Datos de la empresa', filled($modelo->cod_actividad) && filled($modelo->direccion), 'paso-datos'],
            ['Sucursal y caja', $tienePuntoVenta, 'paso-sucursal'],
            ['Credenciales de Hacienda', count($credenciales) > 0, 'paso-credenciales'],
            ['Pruebas de Hacienda', $pruebas->isNotEmpty() && $pruebas->every(fn ($p) => $p->estado === 'completada'), 'paso-pruebas'],
            ['Producción', $modelo->ambiente === '01', 'paso-produccion'],
        ];
        $actual = collect($pasos)->search(fn ($p) => ! $p[1]);
    @endphp
    <div class="encabezado">
        <div>
            <h1>Empresa y Hacienda</h1>
            <p>Cinco pasos para facturar con validez legal. Escobar Net nunca le pedirá contraseñas por chat ni WhatsApp: cárguelas usted mismo aquí.</p>
        </div>
        @if ($modelo->ambiente === '01')
            <span class="insignia-verde text-sm"><span class="size-1.5 rounded-full bg-emerald-500"></span>Producción: documentos con validez legal</span>
        @else
            <span class="insignia-ambar text-sm"><span class="size-1.5 rounded-full bg-amber-500"></span>Pruebas: documentos sin valor legal</span>
        @endif
    </div>
    @if ($error)<div class="alerta-error" role="alert"><x-icono nombre="alerta" clase="size-5 shrink-0"/>{{ $error }}</div>@endif
    @if ($mensaje)<div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>{{ $mensaje }}</div>@endif

    {{-- Avance de los 5 pasos --}}
    <nav class="card !py-4" aria-label="Avance de la configuración">
        <ol class="grid gap-3 sm:grid-cols-5">
            @foreach ($pasos as $i => [$titulo, $listo, $ancla])
                <li>
                    <a href="#{{ $ancla }}" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-50">
                        @if ($listo)
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><x-icono nombre="ok" clase="size-4"/></span>
                        @else
                            <span @class(['flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', 'bg-marca-600 text-white ring-4 ring-marca-100' => $i === $actual, 'border-2 border-slate-200 text-slate-400' => $i !== $actual])>{{ $i + 1 }}</span>
                        @endif
                        <span class="leading-tight">
                            <span @class(['block text-xs font-semibold', 'text-tinta' => $listo || $i === $actual, 'text-slate-400' => ! $listo && $i !== $actual])>{{ $titulo }}</span>
                            <span class="block text-[11px] text-slate-500">{{ $listo ? 'Listo' : ($i === $actual ? 'Siguiente paso' : 'Pendiente') }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </nav>

    <section class="card scroll-mt-24" id="paso-datos">
        <div class="card-titulo"><span class="paso">1</span>Datos de la empresa</div>
        <form wire:submit="guardarEmpresa" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" novalidate>
            <x-campo etiqueta="NIT" campo="empresa.nit" ayuda="Lo registra Escobar Net al dar de alta la empresa.">
                <input value="{{ $modelo->nit }}" disabled class="bg-slate-50">
            </x-campo>
            <x-campo etiqueta="NRC" campo="empresa.nrc" requerido ayuda="2 a 8 dígitos, sin guiones.">
                <input wire:model.blur="empresa.nrc" maxlength="10" inputmode="numeric">
            </x-campo>
            <x-campo etiqueta="Tipo de contribuyente" campo="empresa.tipo_contribuyente" requerido>
                <select wire:model="empresa.tipo_contribuyente"><option value="grande">Grande</option><option value="mediano">Mediano</option><option value="otro">Otro</option></select>
            </x-campo>
            <x-campo etiqueta="Razón social" campo="empresa.razon_social" requerido class="sm:col-span-2" ayuda="Como aparece en su NIT. Máximo 250 caracteres.">
                <input wire:model.blur="empresa.razon_social" maxlength="250">
            </x-campo>
            <x-campo etiqueta="Nombre comercial" campo="empresa.nombre_comercial" ayuda="Opcional, máximo 150 caracteres.">
                <input wire:model.blur="empresa.nombre_comercial" maxlength="150">
            </x-campo>
            <x-campo etiqueta="Actividad económica (catálogo de Hacienda)" campo="empresa.cod_actividad" requerido class="sm:col-span-2" ayuda="Escriba el código o parte del nombre y elija de la lista (CAT-019).">
                <input wire:model.blur="empresa.cod_actividad" list="actividades-empresa" maxlength="6" autocomplete="off">
                <datalist id="actividades-empresa">@foreach ($actividades as $c => $n)<option value="{{ $c }}">{{ $c }} · {{ $n }}</option>@endforeach</datalist>
            </x-campo>
            <x-campo etiqueta="Descripción de la actividad" campo="empresa.desc_actividad" requerido ayuda="Se completa sola al elegir la actividad.">
                <input wire:model.blur="empresa.desc_actividad" maxlength="150">
            </x-campo>
            <x-campo etiqueta="Teléfono" campo="empresa.telefono" requerido ayuda="De 8 a 30 caracteres.">
                <input type="tel" wire:model.blur="empresa.telefono" maxlength="30">
            </x-campo>
            <x-campo etiqueta="Correo" campo="empresa.correo" requerido ayuda="Aparece en sus documentos. Máximo 100 caracteres.">
                <input type="email" wire:model.blur="empresa.correo" maxlength="100">
            </x-campo>
            <div class="hidden lg:block"></div>
            <x-campo etiqueta="Departamento" campo="empresa.departamento" requerido>
                <select wire:model.live="empresa.departamento"><option value="">Elija…</option>@foreach ($departamentos as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
            </x-campo>
            <x-campo etiqueta="Municipio" campo="empresa.municipio" requerido>
                <select wire:model.live="empresa.municipio" @disabled($municipios === [])><option value="">{{ $municipios === [] ? 'Primero elija el departamento' : 'Elija…' }}</option>@foreach ($municipios as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
            </x-campo>
            <x-campo etiqueta="Distrito" campo="empresa.distrito" requerido>
                <select wire:model.live="empresa.distrito" @disabled($distritos === [])><option value="">{{ $distritos === [] ? 'Primero elija el departamento' : 'Elija…' }}</option>@foreach ($distritos as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
            </x-campo>
            <x-campo etiqueta="Dirección (complemento)" campo="empresa.complemento" requerido class="sm:col-span-2 lg:col-span-3" ayuda="Colonia, calle, número. Máximo 200 caracteres.">
                <input wire:model.blur="empresa.complemento" maxlength="200">
            </x-campo>
            <x-campo etiqueta="Responsable ante Hacienda" campo="empresa.responsable_nombre" requerido ayuda="Quien firma las invalidaciones.">
                <input wire:model.blur="empresa.responsable_nombre" maxlength="100">
            </x-campo>
            <x-campo etiqueta="Tipo de documento del responsable" campo="empresa.responsable_tipo_doc" requerido>
                <select wire:model.live="empresa.responsable_tipo_doc">@foreach ($tiposDocumento as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select>
            </x-campo>
            <x-campo etiqueta="Número de documento del responsable" campo="empresa.responsable_num_doc" requerido :ayuda="($empresa['responsable_tipo_doc'] ?? '') === '13' ? 'DUI: 9 dígitos; se guarda como 00000000-0.' : (($empresa['responsable_tipo_doc'] ?? '') === '36' ? 'NIT: 9 o 14 dígitos, sin guiones.' : 'Hasta 20 caracteres.')">
                <input wire:model.blur="empresa.responsable_num_doc" maxlength="20">
            </x-campo>
            <div class="flex flex-wrap gap-6 sm:col-span-2 lg:col-span-3">
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="empresa.agente_retencion"> Agente de retención</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="empresa.agente_percepcion"> Agente de percepción</label>
            </div>
            <div class="flex justify-end sm:col-span-2 lg:col-span-3"><button class="btn-primario">Guardar datos</button></div>
        </form>
    </section>

    <section class="card scroll-mt-24" id="paso-sucursal">
        <div class="card-titulo"><span class="paso">2</span>Establecimiento y punto de venta</div>
        <p>@if ($tienePuntoVenta)<span class="insignia-verde">Listo</span> Ya tiene al menos un punto de venta.@else Cree su casa matriz y su punto de venta con los códigos que le asignó Hacienda.@endif
            <a class="underline" href="{{ route('panel.establecimientos') }}">Establecimientos</a></p>
    </section>

    <section class="card scroll-mt-24" id="paso-credenciales">
        <div class="card-titulo"><span class="paso">3</span>Credenciales de Hacienda</div>
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
            <label>Ambiente<select wire:model="ambienteCredencial" class="mt-1 w-full"><option value="00">Pruebas</option><option value="01">Producción</option></select></label>
            <label>Certificado (.crt)<input type="file" wire:model="certificado" class="mt-1 w-full text-sm"></label>
            <label>Contraseña de la API<input type="password" wire:model="pwdApi" autocomplete="new-password" class="mt-1 w-full"></label>
            <label>Contraseña de la llave privada<input type="password" wire:model="pwdPrivada" autocomplete="new-password" class="mt-1 w-full"></label>
            @error('pwdApi')<p class="text-sm text-red-700 sm:col-span-4">{{ $message }}</p>@enderror
            @error('certificado')<p class="text-sm text-red-700 sm:col-span-4">{{ $message }}</p>@enderror
            <button class="btn-primario sm:col-span-4">Guardar credenciales</button>
        </form>
    </section>

    <section class="card scroll-mt-24" id="paso-pruebas">
        <div class="card-titulo"><span class="paso">4</span>Pruebas mínimas (ambiente de pruebas)</div>
        <p class="mb-3 text-sm text-slate-600">Marque solo los documentos que su empresa va a usar. Entre paréntesis, cuántos exige Hacienda. La plataforma los envía sola; si algo falla, se detiene y le explica qué corregir.</p>
        <div class="mb-4 flex flex-wrap gap-2 text-sm">
            @foreach ($tiposDisponibles as $tipo => $n)<label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 hover:bg-slate-50"><input type="checkbox" value="{{ $tipo }}" wire:model="tiposPrueba"> <span>{{ $nombresTipo[$tipo] ?? $tipo }} <span class="text-xs text-slate-400">({{ $n }})</span></span></label>@endforeach
        </div>
        <button wire:click="ejecutarPruebas" class="btn-primario">Ejecutar o reanudar</button>
        <div class="mt-3 space-y-2">
            @foreach ($pruebas as $p)
                <div><div class="flex justify-between text-sm"><span class="font-medium">{{ $nombresTipo[$p->tipo] ?? $p->tipo }} <span class="text-xs font-normal text-slate-500">· {{ str_replace('_', ' ', $p->estado) }}</span></span><span>{{ $p->exitosas }}/{{ $p->requeridas }}</span></div>
                    <progress class="barra" max="100" value="{{ $p->porcentaje() }}" aria-label="Avance de {{ $p->tipo }}">{{ $p->porcentaje() }} %</progress>
                    @if ($p->ultimo_error && $p->estado !== 'completada')<p class="text-xs text-red-700">{{ $p->ultimo_error }}</p>@endif</div>
            @endforeach
        </div>
    </section>

    <section class="card scroll-mt-24" id="paso-produccion">
        <div class="card-titulo"><span class="paso">5</span>Autorización y paso a producción</div>
        <p class="mb-2 text-sm text-slate-600">En el portal de Hacienda (pantalla Inicio) verifique las barras de avance y solicite la autorización. Una vez autorizado, genere el certificado y el usuario de la API de PRODUCCIÓN, cárguelos arriba (ambiente Producción) y pase a producción.</p>
        <button wire:click="pasarAProduccion" wire:confirm="Desde ahora sus documentos tendrán validez legal. ¿Continuar?" class="btn-peligro" @disabled($modelo->ambiente === '01')>Pasar a producción</button>
    </section>
</div>
