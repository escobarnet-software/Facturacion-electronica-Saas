<?php

declare(strict_types=1);

namespace App\Dte\Correlativos;

use App\Dte\Support\NumeroControl;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use DateTimeInterface;

/**
 * Numero de control DTE-{tipo}-{establecimiento}{punto de venta}-{correlativo 15},
 * con los códigos que asigna Hacienda (cod_estable_mh, cod_punto_venta_mh).
 */
final class GeneradorNumeroControl
{
    public function __construct(private readonly Correlativos $correlativos) {}

    /**
     * @return array{correlativo: int, numero_control: string}
     */
    public function siguiente(
        Empresa $empresa,
        string $tipoDte,
        Establecimiento $establecimiento,
        PuntoVenta $puntoVenta,
        DateTimeInterface $fechaEmision,
    ): array {
        $correlativo = $this->correlativos->siguiente($empresa, $tipoDte, $establecimiento, $puntoVenta, $fechaEmision);

        return [
            'correlativo' => $correlativo,
            'numero_control' => NumeroControl::generar($tipoDte, $establecimiento->cod_estable_mh, $puntoVenta->cod_punto_venta_mh, $correlativo),
        ];
    }
}
