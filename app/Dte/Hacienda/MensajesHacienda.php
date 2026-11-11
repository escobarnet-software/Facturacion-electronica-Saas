<?php

declare(strict_types=1);

namespace App\Dte\Hacienda;

/** Traduce codigoMsg de Hacienda a un mensaje simple en español (config/dte/mensajes_mh.php). */
final class MensajesHacienda
{
    public static function traducir(?string $codigoMsg, ?string $descripcionMsg = null): string
    {
        $codigo = $codigoMsg !== null && ctype_digit(ltrim($codigoMsg, '0') ?: '0') ? (int) $codigoMsg : null;
        /** @var array<int, string> $mensajes */
        $mensajes = config('dte.mensajes_mh', []);
        $texto = $codigo !== null ? ($mensajes[$codigo] ?? null) : null;

        $detalle = trim((string) $descripcionMsg);
        if ($texto === null) {
            return $detalle !== '' ? "Hacienda respondió: {$detalle}" : 'Hacienda rechazó el documento sin indicar el motivo.';
        }

        return $detalle !== '' && ! in_array(mb_strtoupper($detalle), ['RECIBIDO', 'RECIBIDO CON OBSERVACIONES'], true)
            ? "{$texto} (Hacienda: {$detalle})"
            : $texto;
    }
}
