@extends('layouts.app')
@section('titulo', 'Documento Tributario Electrónico · '.$empresa->razon_social)
@section('contenido')
<div class="tarjeta">
    <h1>Documento Tributario Electrónico</h1>
    @if ($dte->ambiente === '00')
        <p class="aviso">SIN VALOR LEGAL — AMBIENTE DE PRUEBAS</p>
    @endif
    <p><strong>Emisor:</strong> {{ $empresa->razon_social }} · NIT {{ $empresa->nit }}</p>
    <p><strong>Modelo de facturación:</strong> {{ $modelo }}<br>
       <strong>Tipo de transmisión:</strong> {{ $transmision }}</p>
    <p><strong>Fecha y hora de generación:</strong> {{ $dte->fecha_emision->format('d/m/Y') }} {{ substr((string) $dte->hora_emision, 0, 8) }}</p>
    <p><strong>Código de generación:</strong> {{ $dte->codigo_generacion }}<br>
       <strong>Sello de recepción:</strong> {{ $dte->sello_recibido ?? 'Pendiente (documento emitido en contingencia)' }}</p>
    <p><img src="{{ $qr }}" alt="Código QR de consulta pública" width="180"></p>
    <p>Descargue el archivo del documento y su representación gráfica. Puede verificar su validez escaneando el código QR, que lo lleva a la consulta pública del Ministerio de Hacienda.</p>
    <p><a href="{{ $urlJson }}">Descargar JSON</a> · <a href="{{ $urlPdf }}">Descargar PDF</a></p>
</div>
@endsection
