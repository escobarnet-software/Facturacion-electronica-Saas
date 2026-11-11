<?php

declare(strict_types=1);

namespace App\Dte\Representacion;

use App\Models\Dte;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/** Código QR de la consulta pública de Hacienda (MTIST, sección 5). */
final class CodigoQr
{
    public static function url(Dte $dte): string
    {
        return strtr((string) config('dte.consulta_publica_url'), [
            '{ambiente}' => $dte->ambiente,
            '{codigoGeneracion}' => $dte->codigo_generacion,
            '{fechaEmi}' => $dte->fecha_emision->format('Y-m-d'),
        ]);
    }

    /** PNG del QR (GD, sin servicios externos). */
    public static function png(string $contenido, int $tamano = 220): string
    {
        return (new Writer(new GDLibRenderer($tamano, 2)))->writeString($contenido);
    }

    public static function dataUri(Dte $dte, int $tamano = 220): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png(self::url($dte), $tamano));
    }
}
