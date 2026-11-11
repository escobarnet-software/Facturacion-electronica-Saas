<?php

declare(strict_types=1);

namespace App\Dte\Factura;

use App\Support\Decimal;

/**
 * Lo que devuelve el FacturaBuilder: el JSON listo para firmar y los datos
 * que el sistema guarda en su base de datos.
 */
final readonly class FacturaResultado
{
    public function __construct(
        public string $codigoGeneracion,
        public string $numeroControl,
        public Decimal $totalPagar,
        public Decimal $totalIva,
        public string $json,
    ) {}

    /**
     * El documento como arreglo (para mostrarlo o inspeccionarlo).
     *
     * @return array<string, mixed>
     */
    public function documento(): array
    {
        return json_decode($this->json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** El mismo JSON con sangría, para leerlo. */
    public function jsonLegible(): string
    {
        return json_encode(
            json_decode($this->json, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
