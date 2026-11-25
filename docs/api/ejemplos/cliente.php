<?php

/**
 * Ejemplo mínimo en PHP (sin dependencias) para la API de Escobar Net DTE.
 * Uso: DTE_URL=https://su-dominio DTE_TOKEN=... php cliente.php
 * El token se crea en el panel (API y webhooks). Nunca lo guarde en el código.
 */
$base = rtrim((string) getenv('DTE_URL'), '/').'/api/v1';
$token = (string) getenv('DTE_TOKEN');

function llamar(string $metodo, string $url, string $token, ?array $cuerpo = null, ?string $clave = null): array
{
    $cabeceras = ['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'];
    if ($clave !== null) {
        $cabeceras[] = 'Idempotency-Key: '.$clave;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $cuerpo === null ? null : json_encode($cuerpo),
    ]);
    $respuesta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, json_decode($respuesta, true)];
}

// Los montos van como TEXTO: la plataforma calcula los totales con decimales exactos.
[$status, $r] = llamar('POST', "{$base}/dte/01", $token, [
    'lineas' => [['descripcion' => 'Pupusa revuelta', 'cantidad' => '6', 'precio' => '0.66371681']],
    'correo' => 'cliente@ejemplo.com',
], clave: 'venta-'.date('Ymd').'-0001'); // reintentar con la misma clave nunca duplica el documento

echo $status, ' ', $r['data']['numero_control'] ?? $r['message'], PHP_EOL;

// Verificar un webhook recibido (en su endpoint):
function firmaValida(string $cuerpo, string $cabecera, string $secreto, int $tolerancia = 300): bool
{
    parse_str(str_replace(',', '&', $cabecera), $p);
    if (! isset($p['t'], $p['v1']) || abs(time() - (int) $p['t']) > $tolerancia) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $p['t'].'.'.$cuerpo, $secreto), (string) $p['v1']);
}
