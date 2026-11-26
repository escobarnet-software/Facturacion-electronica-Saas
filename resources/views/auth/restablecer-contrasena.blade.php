@extends('layouts.app')
@section('titulo', 'Nueva contraseña · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>Crear una nueva contraseña</h1>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email', $request->query('email')) }}" required autocomplete="username">
        <label for="password">Nueva contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas y números)</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        <label for="password_confirmation">Repita la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        <button type="submit">Guardar contraseña</button>
    </form>
</div>
@endsection
