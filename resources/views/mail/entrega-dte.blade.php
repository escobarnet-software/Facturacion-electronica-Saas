{{ $d['emisor'] }} (NIT {{ $d['nitEmisor'] }}) le entrega el siguiente Documento Tributario Electrónico:

Tipo de documento: {{ $d['tipo'] }}
Número de control: {{ $d['numeroControl'] }}
Código de generación: {{ $d['codigoGeneracion'] }}
Sello de recepción: {{ $d['sello'] ?? 'Pendiente (documento emitido en contingencia)' }}
Fecha de generación: {{ $d['fecha'] }}
@if ($d['total'] !== null)
Total: {{ $d['total'] }}
@endif

Adjuntos: el archivo del documento ({{ $d['codigoGeneracion'] }}.json) y su representación gráfica ({{ $d['codigoGeneracion'] }}.pdf).
También puede descargarlos aquí: {{ $d['enlace'] }}
@if ($d['contingencia'])

Este documento se emitió en contingencia y se transmitirá al Ministerio de Hacienda al restablecerse el servicio.
@endif
