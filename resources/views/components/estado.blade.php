@props(['estado'])
@php
    $e = $estado instanceof \App\Enums\EstadoDte ? $estado : \App\Enums\EstadoDte::from((string) $estado);
    $clase = match ($e) {
        \App\Enums\EstadoDte::Transmitido => 'insignia-verde',
        \App\Enums\EstadoDte::Observado, \App\Enums\EstadoDte::Ajustado => 'insignia-azul',
        \App\Enums\EstadoDte::Transitorio, \App\Enums\EstadoDte::Enviado, \App\Enums\EstadoDte::Firmado, \App\Enums\EstadoDte::Validado => 'insignia-ambar',
        \App\Enums\EstadoDte::Rechazado => 'insignia-roja',
        default => 'insignia-gris',
    };
@endphp
<span class="{{ $clase }}"><span class="size-1.5 rounded-full bg-current"></span>{{ $e->etiqueta() }}</span>
