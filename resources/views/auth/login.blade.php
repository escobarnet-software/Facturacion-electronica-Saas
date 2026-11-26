@extends('layouts.app')
@section('titulo', 'Iniciar sesión · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>Iniciar sesión</h1>
    <p>Ingrese con su correo y contraseña.</p>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        <button type="submit">Entrar</button>
    </form>
    <p style="margin-top:1rem;text-align:center"><a href="{{ route('password.request') }}">¿Olvidó su contraseña?</a></p>
</div>
@endsection
