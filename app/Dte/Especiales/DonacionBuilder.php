<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Support\NumeroALetras;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeImmutable;

/**
 * Comprobante de Donación (15, v2). Lo emite el DONATARIO; el receptor es el donante.
 *
 * Manual Funcional v2.0, sección XX (CAT-026):
 *   1 Efectivo y 3 Servicio: depreciación $0.00.  2 Bien: depreciación del bien usado (si aplica).
 *   valor donado = valorUni × cantidad − depreciación.
 *   Los pagos (forma de donación) solo cuentan lo donado en EFECTIVO.
 *   otrosDocumentos es obligatorio y solo admite los códigos 1 (emisor) y 2 (receptor).
 */
final class DonacionBuilder
{
    use ArmaDte;

    public const EFECTIVO = 1;

    public const BIEN = 2;

    public const SERVICIO = 3;

    /**
     * @param  array{tipoDocumento: string, numDocumento: string, nombre: string, codDomiciliado: int, codPais: string, nrc?: string|null, codActividad?: string|null, descActividad?: string|null, direccion?: array{departamento: string, municipio: string, distrito: string, complemento: string}|null, telefono?: string|null, correo?: string|null}  $donante
     * @param  list<array{tipoDonacion: int, descripcion: string, cantidad: string, valorUni: string, depreciacion?: string, uniMedida?: int, codigo?: string|null}>  $donaciones
     * @param  list<array{codDocAsociado: int, descDocumento: string, detalleDocumento: string}>  $otrosDocumentos
     * @param  list<Pago>  $pagos
     */
    public function construir(
        Emisor $donatario,
        string $tipoDocumentoDonatario,
        array $donante,
        array $donaciones,
        array $otrosDocumentos,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        array $pagos = [],
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($donaciones === [] || count($donaciones) > 2000) {
            throw new DteException('El comprobante de donación debe tener entre 1 y 2,000 ítems.');
        }
        if ($otrosDocumentos === []) {
            throw new DteException('El comprobante de donación necesita al menos un documento asociado.');
        }
        foreach ($otrosDocumentos as $o) {
            if (! in_array($o['codDocAsociado'], [1, 2], true)) {
                throw new DteException('En el comprobante de donación los documentos asociados solo pueden ser del emisor (1) o del receptor (2).');
            }
        }

        $cuerpo = [];
        $total = Decimal::zero();
        $efectivo = Decimal::zero();
        foreach (array_values($donaciones) as $i => $d) {
            $n = $i + 1;
            $this->validarDescripcion($d['descripcion'], $n);
            $tipo = $d['tipoDonacion'];
            if (! in_array($tipo, [self::EFECTIVO, self::BIEN, self::SERVICIO], true)) {
                throw new DteException("Ítem {$n}: tipo de donación inválido (CAT-026: 1 efectivo, 2 bien, 3 servicio).");
            }
            $cantidad = Decimal::of($d['cantidad'])->round(8);
            $valorUni = Decimal::of($d['valorUni'])->round(8);
            $depreciacion = Decimal::of($d['depreciacion'] ?? '0')->round(8);
            if ($tipo !== self::BIEN && ! $depreciacion->isZero()) {
                throw new DteException("Ítem {$n}: solo las donaciones de bienes llevan depreciación.");
            }
            if (! $cantidad->isPositive() || $valorUni->isNegative() || $depreciacion->isNegative()) {
                throw new DteException("Ítem {$n}: cantidad, valor o depreciación inválidos.");
            }
            $valor = $valorUni->mul($cantidad)->sub($depreciacion)->round(8);
            if ($valor->isNegative()) {
                throw new DteException("Ítem {$n}: la depreciación es mayor que el valor del bien.");
            }
            $total = $total->add($valor);
            if ($tipo === self::EFECTIVO) {
                $efectivo = $efectivo->add($valor);
            }
            $cuerpo[] = [
                'numItem' => $n, 'tipoDonacion' => $tipo, 'cantidad' => $cantidad, 'codigo' => $d['codigo'] ?? null,
                'uniMedida' => $d['uniMedida'] ?? 99, 'descripcion' => $d['descripcion'],
                'tipoDepreciacion' => $depreciacion, 'valorUni' => $valorUni, 'valor' => $valor,
            ];
        }

        $valorTotal = $total->round(2);
        $efectivo = $efectivo->round(2);
        // El esquema del CD no lleva plazo ni período en los pagos.
        $pagosJson = $efectivo->isZero() ? null : array_map(
            fn (array $p) => ['codigo' => $p['codigo'], 'montoPago' => $p['montoPago'], 'referencia' => $p['referencia']],
            $this->pagosQueSuman($pagos, $efectivo, 'lo donado en efectivo'),
        );

        // El Comprobante de Donación no se emite en contingencia: su esquema no tiene esos campos.
        [$identificacion, $numeroControl, $codigo] = $this->identificacion('15', 2, $ambiente, $donatario, $correlativo, $fechaHora, $codigoGeneracion, admiteContingencia: false);

        $documento = [
            'identificacion' => $identificacion,
            'emisor' => [ // el donatario
                'tipoDocumento' => $tipoDocumentoDonatario, 'numDocumento' => $donatario->nit, 'nrc' => $donatario->nrc,
                'nombre' => $donatario->nombre, 'codActividad' => $donatario->codActividad, 'descActividad' => $donatario->descActividad,
                'nombreComercial' => $donatario->nombreComercial, 'direccion' => $this->direccionEmisor($donatario),
                'telefono' => $donatario->telefono, 'correo' => $donatario->correo,
                'codEstable' => $donatario->codEstable, 'codPuntoVenta' => $donatario->codPuntoVenta,
            ],
            'receptor' => [ // el donante
                'tipoDocumento' => $donante['tipoDocumento'], 'numDocumento' => $donante['numDocumento'], 'nrc' => $donante['nrc'] ?? null,
                'nombre' => $donante['nombre'], 'codActividad' => $donante['codActividad'] ?? null, 'descActividad' => $donante['descActividad'] ?? null,
                'direccion' => $donante['direccion'] ?? null, 'telefono' => $donante['telefono'] ?? null, 'correo' => $donante['correo'] ?? null,
                'codDomiciliado' => $donante['codDomiciliado'], 'codPais' => $donante['codPais'],
            ],
            'otrosDocumentos' => $otrosDocumentos,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => ['valorTotal' => $valorTotal, 'totalLetras' => NumeroALetras::convertir($valorTotal), 'pagos' => $pagosJson],
            'apendice' => null,
        ];

        return new DteArmado('15', 2, $codigo, $numeroControl, ['valorTotal' => $valorTotal, 'efectivo' => $efectivo], DteJson::encode($documento));
    }
}
