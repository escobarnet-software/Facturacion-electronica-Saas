<div class="space-y-6">
    <div>
        <h1>Mi cuenta</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $usuario->nombre }} · {{ $usuario->email }}</p>
    </div>
    @if ($mensaje)<div class="alerta-exito" role="status"><x-icono nombre="ok" clase="size-5 shrink-0"/>{{ $mensaje }}</div>@endif

    <form wire:submit="cambiarContrasena" class="card" novalidate>
        <div class="card-titulo"><x-icono nombre="escudo" clase="size-5 text-marca-600"/>Cambiar contraseña</div>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-campo etiqueta="Contraseña actual" campo="actual" requerido><input type="password" wire:model="actual" autocomplete="current-password"></x-campo>
            <x-campo etiqueta="Nueva contraseña" campo="nueva" requerido ayuda="Mínimo 12 caracteres, con mayúsculas, minúsculas y números."><input type="password" wire:model="nueva" autocomplete="new-password"></x-campo>
            <x-campo etiqueta="Repita la nueva contraseña" campo="nueva_confirmation" requerido><input type="password" wire:model="nueva_confirmation" autocomplete="new-password"></x-campo>
        </div>
        <button class="btn-primario mt-4">Cambiar contraseña</button>
    </form>

    <section class="card">
        <div class="card-titulo"><x-icono nombre="escudo" clase="size-5 text-marca-600"/>Verificación en dos pasos</div>
        @if ($usuario->tiene2fa())
            <p class="text-sm"><span class="insignia-verde">Activa</span> Al entrar se pide el código de 6 dígitos de su aplicación de autenticación.</p>
            <p class="mt-3 text-sm text-slate-600">Los <strong>códigos de recuperación</strong> sirven para entrar si pierde el celular. Cada uno se usa una sola vez. Guárdelos impresos o en un lugar seguro, nunca en un chat.</p>
            <form wire:submit="verCodigos" class="mt-3 flex flex-wrap items-end gap-3" novalidate>
                <x-campo etiqueta="Su contraseña" campo="claveCodigos"><input type="password" wire:model="claveCodigos" autocomplete="current-password"></x-campo>
                <button class="btn-secundario">Ver códigos</button>
                <button type="button" wire:click="generarCodigos" wire:confirm="¿Generar códigos nuevos? Los anteriores dejarán de servir." class="btn-secundario">Generar códigos nuevos</button>
            </form>
            @if ($codigos !== [])
                <ul class="mt-4 grid max-w-md grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm">
                    @foreach ($codigos as $c)<li>{{ $c }}</li>@endforeach
                </ul>
            @endif
        @else
            <p class="text-sm text-slate-600">No está activa. @if ($usuario->exige2fa())Es obligatoria para su cuenta.@else Es opcional, pero recomendada.@endif</p>
            <a href="{{ route('seguridad.2fa') }}" class="btn-primario mt-3 inline-flex">Activar verificación en dos pasos</a>
        @endif
    </section>
</div>
