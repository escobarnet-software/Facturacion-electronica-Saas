@php($ticket = $formato === 'ticket')
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $titulo }}</title>
<style>
    @page { margin: {{ $ticket ? '6pt' : '28pt' }}; }
    body { font-family: DejaVu Sans, sans-serif; font-size: {{ $ticket ? '6.5pt' : '8pt' }}; color: #111; }
    h1 { font-size: {{ $ticket ? '9pt' : '13pt' }}; margin: 0 0 2pt; text-align: center; }
    h2 { font-size: {{ $ticket ? '7pt' : '9pt' }}; background: #e9edf2; padding: 2pt 4pt; margin: 6pt 0 3pt; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 1.5pt 3pt; vertical-align: top; }
    .datos td:first-child { width: 40%; color: #444; }
    .tabla th { background: #f2f4f7; border: 0.5pt solid #bbb; font-weight: bold; text-align: left; }
    .tabla td { border: 0.5pt solid #ccc; }
    .num { text-align: right; white-space: nowrap; }
    .centro { text-align: center; }
    .aviso { border: 1pt solid #b42318; color: #b42318; padding: 3pt; margin: 4pt 0; text-align: center; font-weight: bold; }
    .marca { position: fixed; top: 38%; left: -10%; width: 120%; text-align: center; font-size: {{ $ticket ? '14pt' : '30pt' }};
             color: rgba(180, 35, 24, 0.22); transform: rotate(-30deg); font-weight: bold; z-index: 1000; }
    .qr img { width: {{ $ticket ? '90pt' : '96pt' }}; }
</style>
</head>
<body>
@if ($pruebas)
    <div class="marca">{{ $marcaPruebas }}</div>
@endif

<table>
    <tr>
        @if ($logo && ! $ticket)<td style="width: 25%"><img src="{{ $logo }}" style="max-width: 120pt; max-height: 60pt"></td>@endif
        <td>
            <div class="centro">DOCUMENTO TRIBUTARIO ELECTRÓNICO</div>
            <h1>{{ $titulo }}</h1>
            <div class="centro">Versión {{ $version }}</div>
        </td>
        @if (! $ticket)<td class="qr centro" style="width: 25%"><img src="{{ $qr }}" alt="QR de consulta pública"></td>@endif
    </tr>
</table>

@if ($pruebas)
    <div class="aviso">{{ $marcaPruebas }}</div>
@endif
@if ($contingencia)
    <div class="aviso">DOCUMENTO EMITIDO EN CONTINGENCIA: se transmitirá a Hacienda al restablecerse el servicio.</div>
@endif

<h2>IDENTIFICACIÓN</h2>
<table class="datos">
    @foreach ($identificacion as [$etiqueta, $valor])
        <tr><td>{{ $etiqueta }}</td><td>{{ $valor }}</td></tr>
    @endforeach
</table>

<h2>EMISOR</h2>
<table class="datos">
    @foreach ($emisor as [$etiqueta, $valor])
        <tr><td>{{ $etiqueta }}</td><td>{{ $valor }}</td></tr>
    @endforeach
</table>

<h2>RECEPTOR</h2>
<table class="datos">
    @forelse ($receptor as [$etiqueta, $valor])
        <tr><td>{{ $etiqueta }}</td><td>{{ $valor }}</td></tr>
    @empty
        <tr><td colspan="2">-</td></tr>
    @endforelse
</table>

@foreach (['DOCUMENTOS RELACIONADOS' => $relacionados, 'DOCUMENTOS ASOCIADOS' => $asociados] as $nombre => $seccion)
    @if ($seccion !== null)
        <h2>{{ $nombre }}</h2>
        @if ($seccion['guion'])
            <div>-</div>
        @else
            <table class="tabla">
                <tr>@foreach ($seccion['columnas'] as $c)<th>{{ $c }}</th>@endforeach</tr>
                @foreach ($seccion['filas'] as $fila)
                    <tr>@foreach ($fila as $v)<td>{{ $v }}</td>@endforeach</tr>
                @endforeach
            </table>
        @endif
    @endif
@endforeach

@if ($ventaTercero !== null)
    <h2>VENTA POR CUENTA DE TERCEROS</h2>
    <table class="datos">
        @foreach ($ventaTercero as [$etiqueta, $valor])
            <tr><td>{{ $etiqueta }}</td><td>{{ $valor }}</td></tr>
        @endforeach
    </table>
@endif

@if ($cuerpo !== null && ! $cuerpo['guion'])
    <h2>CUERPO DEL DOCUMENTO</h2>
    <table class="tabla">
        <tr>@foreach ($cuerpo['columnas'] as $c)<th>{{ $c }}</th>@endforeach</tr>
        @foreach ($cuerpo['filas'] as $fila)
            <tr>@foreach ($fila as $v)<td class="{{ str_starts_with($v, '$') || str_starts_with($v, '-$') ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr>
        @endforeach
    </table>
@endif

<h2>RESUMEN</h2>
<table class="datos">
    @foreach ($resumen as [$etiqueta, $valor])
        <tr><td>{{ $etiqueta }}</td><td class="num">{{ $valor }}</td></tr>
    @endforeach
    @if ($totalLetras)
        <tr><td>Valor en letras</td><td>{{ $totalLetras }}</td></tr>
    @endif
</table>

@if ($extension !== [])
    <h2>EXTENSIÓN</h2>
    <table class="datos">
        @foreach ($extension as [$etiqueta, $valor])
            <tr><td>{{ $etiqueta }}</td><td>{{ $valor }}</td></tr>
        @endforeach
    </table>
@endif

@if ($observaciones)
    <h2>OBSERVACIONES</h2>
    <div>{{ $observaciones }}</div>
@endif

@if ($ticket)
    <div class="qr centro" style="margin-top: 6pt"><img src="{{ $qr }}" alt="QR de consulta pública"></div>
@endif
<div class="centro" style="margin-top: 4pt; font-size: {{ $ticket ? '5.5pt' : '7pt' }}">
    Consulte este documento en el sitio del Ministerio de Hacienda escaneando el código QR o en:<br>{{ $urlConsulta }}
</div>
</body>
</html>
