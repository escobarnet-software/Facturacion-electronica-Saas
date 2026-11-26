Hola:

{{ $invitadoPor }} le dio acceso a {{ $empresa }} en Escobar Net DTE, con el rol de {{ $rol }}.

@if ($cuentaNueva)
Para activar su cuenta, cree su contraseña en este enlace (vence en 72 horas y sirve una sola vez):
@else
Ya puede entrar con su correo y su contraseña de siempre:
@endif

{{ $enlace }}

Si usted no esperaba este correo, ignórelo.

Escobar Net
