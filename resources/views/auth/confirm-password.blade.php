@extends('layouts.app')
@section('titulo', 'Confirmar contraseña · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>Confirme su contraseña</h1>
    <form method="POST" action="{{ url('/user/confirm-password') }}">
        @csrf
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        <button type="submit">Confirmar</button>
    </form>
</div>
@endsection
