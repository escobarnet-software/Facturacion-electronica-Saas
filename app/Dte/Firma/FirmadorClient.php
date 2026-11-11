<?php

declare(strict_types=1);

namespace App\Dte\Firma;

use App\Dte\Excepciones\DteException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del firmador oficial de Hacienda (svfe-api-firmador), en la red interna.
 *
 *   POST /firmardocumento/  {"nit": "...", "activo": true, "passwordPri": "...", "dteJson": {...}}
 *   → {"status": "OK", "body": "<JWS compacto>"} | {"status": "ERROR", "body": {"codigo": "...", "mensaje": "..."}}
 *
 * El dteJson se envía con su texto EXACTO (sin decodificar ni volver a codificar),
 * para no alterar ningún número. La clave privada nunca aparece en errores ni logs.
 */
final class FirmadorClient
{
    public function firmar(string $nit, #[\SensitiveParameter] string $passwordPri, string $dteJson): string
    {
        $cuerpo = '{"nit":'.json_encode($nit).',"activo":true,"passwordPri":'
            .json_encode($passwordPri, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            .',"dteJson":'.$dteJson.'}';

        try {
            $respuesta = Http::timeout((int) config('dte.firmador.timeout_segundos'))
                ->withBody($cuerpo, 'application/json')
                ->post((string) config('dte.firmador.url'));
        } catch (ConnectionException) {
            throw new DteException('El servicio de firma no responde. Intente de nuevo en unos minutos.');
        }

        $datos = $respuesta->json();
        if (is_array($datos) && ($datos['status'] ?? null) === 'OK' && is_string($datos['body'] ?? null) && $datos['body'] !== '') {
            return $datos['body'];
        }

        $codigo = is_array($datos) ? (string) ($datos['body']['codigo'] ?? '') : '';
        $mensaje = match ($codigo) {
            '803' => 'No se encontró el certificado de la empresa en el firmador. Cárguelo de nuevo desde Credenciales DTE.',
            '809', '810' => 'La contraseña de la llave privada no es correcta. Actualícela desde Credenciales DTE.',
            default => 'No se pudo firmar el documento'.($codigo !== '' ? " (código {$codigo} del firmador)." : '.'),
        };

        throw new DteException($mensaje);
    }
}
