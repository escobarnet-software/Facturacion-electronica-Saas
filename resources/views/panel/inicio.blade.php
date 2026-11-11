@extends('layouts.app')
@section('titulo', 'Panel · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>{{ $empresa?->nombre_comercial ?? $empresa?->razon_social ?? 'Sin empresa' }}</h1>
    @if ($empresa)
        <p>NIT {{ $empresa->nit }} · Ambiente {{ $empresa->ambiente === '01' ? 'producción' : 'pruebas' }}</p>
    @endif
    @if ($empresas->count() > 1)
        <form method="POST" action="{{ route('empresa-activa') }}">
            @csrf
            <label for="empresa_id">Cambiar de empresa</label>
            <select id="empresa_id" name="empresa_id">
                @foreach ($empresas as $opcion)
                    <option value="{{ $opcion->id }}" @selected($opcion->id === $empresa?->id)>{{ $opcion->razon_social }}</option>
                @endforeach
            </select>
            <button type="submit">Cambiar</button>
        </form>
    @endif
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Cerrar sesión</button>
    </form>
</div>
@endsection
