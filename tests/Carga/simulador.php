<?php

/**
 * Simulador de Hacienda (ambiente de pruebas) y del firmador, para la demo y la prueba de carga.
 * NUNCA se conecta a Hacienda. Responde siempre como si todo fuera aceptado, con sellos ficticios.
 *
 * Uso: PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8899 tests/Carga/simulador.php
 * SIMULADOR_LATENCIA_MS agrega una espera por petición (por defecto 30 ms).
 *
 * Para probar la contingencia: abra /simulador/hacienda-caida (Hacienda responde 503, el firmador sigue)
 * y luego /simulador/hacienda-arriba. /simulador/estado muestra cómo está.
 *
 * Servicios (MTIST v2, sección 4): firmador, autenticación, recepción, consulta, lote, consulta de lote,
 * contingencia e invalidación. Los retornos y operaciones especiales usan la recepción.
 */
usleep(((int) (getenv('SIMULADOR_LATENCIA_MS') ?: 30)) * 1000);
header('Content-Type: application/json');

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
$ahora = date('d/m/Y H:i:s');
$sello = fn (string $codigo) => strtoupper(substr(sha1('sello'.$codigo), 0, 40));

/** codigoGeneracion dentro del payload de un JWS (el firmador simulado pone el documento tal cual). */
$codigoDelJws = function (?string $jws): string {
    $parte = explode('.', (string) $jws)[1] ?? '';
    $doc = json_decode((string) base64_decode(strtr($parte, '-_', '+/')), true) ?: [];

    return strtoupper((string) ($doc['identificacion']['codigoGeneracion'] ?? ''));
};
$procesado = fn (string $codigo) => [
    'version' => 2, 'ambiente' => '00', 'versionApp' => 2, 'estado' => 'PROCESADO',
    'codigoGeneracion' => $codigo, 'selloRecibido' => $sello($codigo), 'fhProcesamiento' => $ahora,
    'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
];
$lotes = sys_get_temp_dir().'/simulador-lotes';
@mkdir($lotes);

$bandera = sys_get_temp_dir().'/simulador-hacienda-caida';
if (str_starts_with($ruta, '/simulador/')) {
    header('Content-Type: text/html; charset=utf-8');
    if ($ruta === '/simulador/hacienda-caida') {
        touch($bandera);
    } elseif ($ruta === '/simulador/hacienda-arriba') {
        @unlink($bandera);
    }
    $caida = file_exists($bandera);
    echo '<!doctype html><meta charset="utf-8"><title>Simulador de Hacienda</title><body style="font-family:sans-serif;margin:2rem">'
        .'<h1>Simulador de Hacienda (demo)</h1><p>Estado: <strong>'.($caida ? 'Hacienda CAÍDA (responde 503)' : 'Hacienda en línea').'</strong></p>'
        .'<p><a href="/simulador/hacienda-caida">Simular caída de Hacienda</a> · <a href="/simulador/hacienda-arriba">Volver a poner Hacienda en línea</a></p>'
        .'<p>El firmador sigue funcionando en ambos casos.</p></body>';

    return;
}
if (file_exists($bandera) && ! str_starts_with($ruta, '/firmardocumento')) {
    http_response_code(503);
    echo json_encode(['estado' => 'ERROR', 'descripcionMsg' => 'Servicio no disponible (caída simulada)']);

    return;
}

if (str_starts_with($ruta, '/firmardocumento')) {
    echo json_encode(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.'.rtrim(strtr(base64_encode(json_encode($cuerpo['dteJson'] ?? [])), '+/', '-_'), '=').'.U0lNVUxBRE8']);
} elseif ($ruta === '/seguridad/auth') {
    echo json_encode(['status' => 'OK', 'body' => ['user' => 'simulado', 'token' => 'Bearer simulado', 'tokenType' => 'Bearer']]);
} elseif ($ruta === '/fesv/recepciondte') {
    echo json_encode($procesado(strtoupper((string) ($cuerpo['codigoGeneracion'] ?? ''))));
} elseif ($ruta === '/fesv/recepcion/consultadte') {
    echo json_encode($procesado(strtoupper((string) ($cuerpo['codigoGeneracion'] ?? ''))));
} elseif ($ruta === '/fesv/anulardte') {
    $codigo = $codigoDelJws($cuerpo['documento'] ?? null);
    echo json_encode([...$procesado($codigo), 'descripcionMsg' => 'Invalidación recibida y procesada']);
} elseif ($ruta === '/fesv/contingencia') {
    $codigo = $codigoDelJws($cuerpo['documento'] ?? null);
    echo json_encode(['estado' => 'RECIBIDO', 'fechaHora' => $ahora, 'mensaje' => 'Evento de contingencia recibido', 'selloRecibido' => $sello($codigo), 'observaciones' => []]);
} elseif ($ruta === '/fesv/recepcionlote') {
    $codigoLote = strtoupper(sprintf('%04X%04X-%04X-4%03X-A%03X-%04X%04X%04X', ...array_map(fn ($n) => random_int(0, $n), [65535, 65535, 65535, 4095, 4095, 65535, 65535, 65535])));
    $codigos = array_map($codigoDelJws, (array) ($cuerpo['documentos'] ?? []));
    file_put_contents("{$lotes}/{$codigoLote}.json", json_encode($codigos));
    echo json_encode(['version' => 2, 'ambiente' => '00', 'estado' => 'RECIBIDO', 'idEnvio' => $cuerpo['idEnvio'] ?? null, 'fhProcesamiento' => $ahora, 'codigoLote' => $codigoLote, 'codigoMsg' => '001', 'descripcionMsg' => 'Lote recibido']);
} elseif (preg_match('#^/fesv/recepcion/consultadtelote/([0-9A-Fa-f-]{36})$#', $ruta, $m)) {
    $codigos = json_decode((string) @file_get_contents("{$lotes}/".strtoupper($m[1]).'.json'), true) ?: [];
    echo json_encode(['procesados' => array_map($procesado, $codigos), 'rechazados' => []]);
} else {
    http_response_code(404);
    echo json_encode(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Servicio no simulado: '.$ruta]);
}
