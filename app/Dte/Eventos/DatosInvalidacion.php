<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

/**
 * Datos que da el usuario para invalidar (Normativa 13.1; CAT-024 tipo de invalidación):
 *  1 = error en la información (requiere el documento que lo reemplaza),
 *  2 = rescindir la operación,
 *  3 = otro (requiere motivo y documento que reemplaza).
 */
final readonly class DatosInvalidacion
{
    public function __construct(
        public int $tipoAnulacion,
        public string $nombreResponsable,
        public string $tipDocResponsable,     // CAT-022
        public string $numDocResponsable,
        public string $nombreSolicita,
        public string $tipDocSolicita,
        public string $numDocSolicita,
        public ?string $motivoAnulacion = null,
        public ?string $codigoGeneracionR = null, // documento que reemplaza (ya emitido y sellado)
    ) {}
}
