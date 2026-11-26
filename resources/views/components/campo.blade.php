@props(['etiqueta', 'campo', 'ayuda' => null, 'requerido' => false])
@php($error = $errors->first($campo))
<div {{ $attributes->class(['campo', 'campo-error' => $error]) }}>
    <label class="mb-1 flex items-baseline gap-1 text-sm font-medium text-slate-700">
        {{ $etiqueta }}@if ($requerido)<span class="text-rose-600" title="Obligatorio para Hacienda" aria-hidden="true">*</span><span class="sr-only">(obligatorio)</span>@endif
    </label>
    {{ $slot }}
    @if ($error)
        <p class="mt-1 flex items-start gap-1 text-xs font-medium text-rose-700" role="alert"><x-icono nombre="alerta" clase="mt-px size-3.5 shrink-0"/>{{ $error }}</p>
    @elseif ($ayuda)
        <p class="mt-1 text-xs text-slate-500">{{ $ayuda }}</p>
    @endif
</div>
