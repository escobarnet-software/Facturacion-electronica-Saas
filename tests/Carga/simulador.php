<?php

/**
 * Simulador de Hacienda y del firmador para la prueba de carga (NUNCA se conecta a Hacienda).
 * Uso: PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8899 tests/Carga/simulador.php
 * SIMULADOR_LATENCIA_MS agrega una espera por petición (por defecto 30 ms) para parecerse a la red real.
 */
usleep(((int) (getenv('SIMULADOR_LATENCIA_MS') ?: 30)) * 1000);
header('Content-Type: application/json');
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];

echo match (true) {
    str_starts_with($ruta, '/firmardocumento') => json_encode(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.'.rtrim(strtr(base64_encode(json_encode($cuerpo['dteJson'] ?? [])), '+/', '-_'), '=').'.U0lNVUxBRE8']),
    $ruta === '/seguridad/auth' => json_encode(['status' => 'OK', 'body' => ['user' => 'simulado', 'token' => 'Bearer simulado', 'tokenType' => 'Bearer']]),
    $ruta === '/fesv/recepciondte' => json_encode([
        'version' => 2, 'ambiente' => '00', 'versionApp' => 2, 'estado' => 'PROCESADO',
        'codigoGeneracion' => $cuerpo['codigoGeneracion'] ?? null,
        'selloRecibido' => strtoupper(substr(sha1((string) ($cuerpo['codigoGeneracion'] ?? '')), 0, 40)),
        'fhProcesamiento' => date('d/m/Y H:i:s'), 'clasificaMsg' => '10', 'codigoMsg' => '001',
        'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
    ]),
    default => (function () {
        http_response_code(404);

        return '{}';
    })(),
};
