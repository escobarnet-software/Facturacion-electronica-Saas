<?php

namespace App\Enums;

/** Estados de un documento (ESPECIFICACION §11, Normativa regla 10.1). */
enum EstadoDte: string
{
    case Borrador = 'borrador';
    case Validado = 'validado';
    case Firmado = 'firmado';
    case Enviado = 'enviado';
    case Transitorio = 'transitorio';   // contingencia o diferido: firmado y entregado, falta el sello
    case Transmitido = 'transmitido';   // con sello
    case Ajustado = 'ajustado';
    case Observado = 'observado';       // con sello y observaciones
    case Rechazado = 'rechazado';
    case Invalidado = 'invalidado';

    public function tieneSello(): bool
    {
        return in_array($this, [self::Transmitido, self::Observado, self::Ajustado, self::Invalidado], true);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Validado => 'Validado',
            self::Firmado => 'Firmado',
            self::Enviado => 'Enviado a Hacienda',
            self::Transitorio => 'Pendiente de transmitir (contingencia)',
            self::Transmitido => 'Transmitido',
            self::Ajustado => 'Ajustado',
            self::Observado => 'Transmitido con observaciones',
            self::Rechazado => 'Rechazado por Hacienda',
            self::Invalidado => 'Invalidado',
        };
    }
}
