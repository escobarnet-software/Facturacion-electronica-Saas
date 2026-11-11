@extends('layouts.app')
@section('titulo', 'Verificación en dos pasos · Escobar Net DTE')
@section('contenido')
<div class="tarjeta">
    <h1>Verificación en dos pasos</h1>
    <p>Escriba el código de 6 dígitos de su aplicación de autenticación.</p>
    <form method="POST" action="{{ url('/two-factor-challenge') }}">
        @csrf
        <label for="code">Código</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <label for="recovery_code">O un código de recuperación</label>
        <input id="recovery_code" name="recovery_code" autocomplete="off">
        <button type="submit">Verificar</button>
    </form>
</div>
@endsection
