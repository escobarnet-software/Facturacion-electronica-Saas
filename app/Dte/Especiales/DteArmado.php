<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Support\Decimal;

/** Documento especial armado (FSE, FEX, CR, CD). */
final readonly class DteArmado
{
    /** @param array<string, Decimal> $totales */
    public function __construct(
        public string $tipoDte,
        public int $version,
        public string $codigoGeneracion,
        public string $numeroControl,
        public array $totales,
        public string $json,
    ) {}

    /** @return array<string, mixed> */
    public function documento(): array
    {
        return json_decode($this->json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function total(string $nombre): Decimal
    {
        return $this->totales[$nombre] ?? throw new \OutOfBoundsException("Total desconocido: {$nombre}");
    }
}
