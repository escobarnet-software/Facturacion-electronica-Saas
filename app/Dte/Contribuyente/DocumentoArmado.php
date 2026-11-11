<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Support\Decimal;

/** Documento listo para validar y firmar, con los datos que se guardan en la tabla dtes. */
final readonly class DocumentoArmado
{
    public function __construct(
        public string $tipoDte,
        public int $version,
        public string $codigoGeneracion,
        public string $numeroControl,
        public CalculoContribuyente $calculo,
        public string $json,
    ) {}

    public function totalPagar(): Decimal
    {
        return $this->calculo->totalPagar;
    }

    /** @return array<string, mixed> */
    public function documento(): array
    {
        return json_decode($this->json, true, flags: JSON_THROW_ON_ERROR);
    }
}
