<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * Valida la URL de destino de un webhook para evitar que la plataforma llame a su propia red interna (SSRF).
 *
 * TODO(paso-14): en producción, además, las salidas de webhooks pasan por un proxy de egreso que
 * bloquea rangos privados aunque el DNS cambie después de registrar la URL.
 */
final class DestinoWebhook
{
    public static function rechazo(string $url): ?string
    {
        $partes = parse_url($url);
        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        $host = strtolower(trim((string) ($partes['host'] ?? ''), '[]'));

        if ($esquema !== 'https' && ! app()->environment('local')) {
            return 'La URL del webhook debe usar https.';
        }
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return 'La URL del webhook debe apuntar a un servidor público.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'La URL del webhook no puede apuntar a una dirección privada o reservada.';
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            return 'La URL del webhook no puede llevar usuario ni contraseña.';
        }

        return null;
    }
}
