<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Modelos\Pago;
use App\Support\Decimal;
use DateTimeImmutable;

/**
 * Datos de negocio para armar un CCF (03), NR (04), NC (05) o ND (06).
 * La plataforma calcula todos los totales.
 */
final readonly class DocumentoContribuyente
{
    public ?Decimal $porcentajeDescuento;

    /** @var array{gravada: Decimal|null, exenta: Decimal|null, noSujeta: Decimal|null} */
    public array $descuentosGlobales;

    /**
     * @param  list<Linea>  $lineas
     * @param  list<DocumentoRelacionado>  $relacionados
     * @param  list<Pago>  $pagos
     */
    public function __construct(
        public array $lineas,
        public ReceptorContribuyente $receptor,
        public int $correlativo,
        public DateTimeImmutable $fechaHora,
        public array $relacionados = [],
        // Descuento global (Manual Funcional XVIII.2/3): porcentaje sobre cada tipo de venta.
        string|int|Decimal|null $porcentajeDescuento = null,
        // Retención/percepción del 1 % de IVA. La plataforma NO decide sola si aplican:
        // TODO(normativa): reglas de agentes (Código Tributario arts. 162 y 163, montos mínimos)
        // pendientes de confirmar; por ahora las indica quien emite.
        public bool $retenerIva1 = false,
        public bool $percibirIva1 = false,
        public array $pagos = [],
        public int $condicionOperacion = 1,
        public ?string $observaciones = null,
        public ?string $bienTitulo = null,        // NR: CAT-025
        public ?string $codigoRetencionMH = null, // NC/ND que ajustan un CR: CAT-006
        // Descuento global por MONTO a cada tipo de venta (alternativa al porcentaje).
        string|int|Decimal|null $descuentoGlobalGravada = null,
        string|int|Decimal|null $descuentoGlobalExenta = null,
        string|int|Decimal|null $descuentoGlobalNoSujeta = null,
    ) {
        $this->porcentajeDescuento = $porcentajeDescuento === null ? null : Decimal::of($porcentajeDescuento);
        $a = fn ($v) => $v === null ? null : Decimal::of($v);
        $this->descuentosGlobales = [
            'gravada' => $a($descuentoGlobalGravada),
            'exenta' => $a($descuentoGlobalExenta),
            'noSujeta' => $a($descuentoGlobalNoSujeta),
        ];
    }
}
