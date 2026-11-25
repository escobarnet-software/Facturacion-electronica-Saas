@extends('layouts.app')
@section('titulo', 'Activar cuenta · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>Activar su cuenta</h1>
    <p>{{ $usuario->email }}</p>
    <form method="POST" action="{{ request()->fullUrl() }}">
        @csrf
        <label for="password">Nueva contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas y números)</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        <label for="password_confirmation">Repita la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        @foreach ($errors->all() as $e)<p role="alert">{{ $e }}</p>@endforeach
        <button type="submit">Activar cuenta</button>
    </form>
</div>
@endsection
