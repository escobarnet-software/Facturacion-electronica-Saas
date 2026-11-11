@extends('layouts.app')
@section('titulo', 'Verificación en dos pasos · Escobar Net DTE')
@section('contenido')
@php($usuario = auth()->user())
<div class="tarjeta">
    <h1>Verificación en dos pasos</h1>
    @if ($usuario->two_factor_confirmed_at)
        <p>La verificación en dos pasos está activa.</p>
        <p><a href="{{ route('panel') }}">Ir al panel</a></p>
    @elseif ($usuario->two_factor_secret)
        <p>Escanee este código con su aplicación de autenticación y escriba el código de 6 dígitos.</p>
        <div>{!! $usuario->twoFactorQrCodeSvg() !!}</div>
        <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}">
            @csrf
            <label for="code">Código</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            <button type="submit">Confirmar</button>
        </form>
    @else
        <p>Los dueños de empresa deben proteger su cuenta con un segundo factor (una aplicación como Google Authenticator).</p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
            @csrf
            <button type="submit">Activar</button>
        </form>
    @endif
</div>
@endsection
