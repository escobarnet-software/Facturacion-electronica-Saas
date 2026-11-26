@extends('layouts.app')
@section('titulo', 'Recuperar contraseña · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>¿Olvidó su contraseña?</h1>
    <p>Escriba el correo con el que entra. Le enviaremos un enlace para crear una nueva.</p>
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255">
        <button type="submit">Enviar enlace</button>
    </form>
    <p style="margin-top:1rem"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
</div>
@endsection
