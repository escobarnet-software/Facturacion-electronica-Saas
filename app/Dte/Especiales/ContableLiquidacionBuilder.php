<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Support\NumeroALetras;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeImmutable;

/**
 * Documento Contable de Liquidación (09, v2): el agente perceptor (por ejemplo, el procesador de
 * tarjetas) liquida al afiliado. El cuerpo es UN objeto con los valores del período.
 *
 * Reglas (Normativa v2.0, Anexo IV, campos 106–122):
 *  - subTotal = valor de las operaciones − valores no sujetos a percepción.
 *  - ivaComision = comisión × 13 %.
 *  - líquido a pagar = valor de las operaciones − comisión − IVA percibido (2 %) − IVA de la comisión.
 *
 * TODO(normativa): la Normativa exige «monto sujeto a percepción sin IVA» e «IVA de las operaciones»
 * (> 0) pero no da su fórmula; los aporta quien emite (vienen de su liquidación). El IVA percibido
 * se calcula como el 2 % del monto sujeto a percepción, con la holgura de ±0.01 de la A.T.
 */
final class ContableLiquidacionBuilder
{
    use ArmaDte;

    public const PERCEPCION = '0.02';

    /**
     * @param  array{nit: string, nrc?: string|null, nombre: string, codActividad: string, descActividad: string, nombreComercial?: string|null, tipoEstablecimiento: string, direccion?: array{departamento: string, municipio: string, distrito: string, complemento: string}|null, telefono?: string|null, correo: string}  $afiliado
     * @param  array{inicio: string, fin: string, valorOperaciones: string, montoSujetoPercepcion: string, iva: string, montoSinPercepcion?: string, descripSinPercepcion?: string|null, comision?: string, porcentComision?: string|null, codLiquidacion?: string|null, cantidadDoc?: int|null, observaciones?: string|null}  $liquidacion
     * @param  array{nombEntrega: string, docuEntrega: string, codEmpleado?: string|null}  $entrega
     */
    public function construir(
        Emisor $emisor,
        array $afiliado,
        array $liquidacion,
        array $entrega,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($liquidacion['fin'] < $liquidacion['inicio']) {
            throw new DteException('La fecha fin del período no puede ser anterior a la fecha de inicio.');
        }
        $d = fn (string $campo, string $defecto = '0') => Decimal::of($liquidacion[$campo] ?? $defecto)->round(2);
        $valor = $d('valorOperaciones');
        $sinPercepcion = $d('montoSinPercepcion');
        $sujeto = $d('montoSujetoPercepcion');
        $iva = $d('iva');
        $comision = $d('comision');
        if (! $valor->isPositive() || ! $sujeto->isPositive() || ! $iva->isPositive()) {
            throw new DteException('El valor de las operaciones, el monto sujeto a percepción y el IVA deben ser mayores que cero.');
        }
        if ($sinPercepcion->isNegative() || $comision->isNegative()) {
            throw new DteException('Los valores no sujetos a percepción y la comisión no pueden ser negativos.');
        }

        $subTotal = $valor->sub($sinPercepcion);
        $ivaPercibido = $sujeto->mul(self::PERCEPCION)->round(2);
        $ivaComision = $comision->mul('0.13')->round(2);
        $liquido = $valor->sub($comision)->sub($ivaPercibido)->sub($ivaComision);
        if (! $subTotal->isPositive() || ! $liquido->isPositive()) {
            throw new DteException('El subtotal y el líquido a pagar al afiliado deben ser mayores que cero.');
        }

        [$identificacion, $numeroControl, $codigo] = $this->identificacion('09', 2, $ambiente, $emisor, $correlativo, $fechaHora, $codigoGeneracion, admiteContingencia: false);

        $documento = [
            'identificacion' => $identificacion,
            'emisor' => [
                'nit' => $emisor->nit, 'nrc' => $emisor->nrc, 'nombre' => $emisor->nombre,
                'codActividad' => $emisor->codActividad, 'descActividad' => $emisor->descActividad,
                'nombreComercial' => $emisor->nombreComercial, 'telefono' => $emisor->telefono, 'correo' => $emisor->correo,
                'direccion' => $this->direccionEmisor($emisor), 'codEstable' => $emisor->codEstable, 'codPuntoVenta' => $emisor->codPuntoVenta,
            ],
            'receptor' => [
                'nit' => $afiliado['nit'], 'nrc' => $afiliado['nrc'] ?? null, 'nombre' => $afiliado['nombre'],
                'codActividad' => $afiliado['codActividad'], 'descActividad' => $afiliado['descActividad'],
                'nombreComercial' => $afiliado['nombreComercial'] ?? null, 'tipoEstablecimiento' => $afiliado['tipoEstablecimiento'],
                'direccion' => $afiliado['direccion'] ?? null, 'telefono' => $afiliado['telefono'] ?? null, 'correo' => $afiliado['correo'],
            ],
            'cuerpoDocumento' => [
                'periodoLiquidacionFechaInicio' => $liquidacion['inicio'],
                'periodoLiquidacionFechaFin' => $liquidacion['fin'],
                'codLiquidacion' => $liquidacion['codLiquidacion'] ?? null,
                'cantidadDoc' => $liquidacion['cantidadDoc'] ?? null,
                'valorOperaciones' => $valor,
                'montoSinPercepcion' => $sinPercepcion,
                'descripSinPercepcion' => $liquidacion['descripSinPercepcion'] ?? null,
                'subTotal' => $subTotal,
                'iva' => $iva,
                'montoSujetoPercepcion' => $sujeto,
                'ivaPercibido' => $ivaPercibido,
                'comision' => $comision,
                // La Normativa dice null si no se usa, pero el esquema exige un número: manda el esquema (0.00).
                'porcentComision' => Decimal::of($liquidacion['porcentComision'] ?? '0')->round(2),
                'ivaComision' => $ivaComision,
                'liquidoApagar' => $liquido,
                'totalLetras' => NumeroALetras::convertir($liquido),
                'observaciones' => $liquidacion['observaciones'] ?? null,
            ],
            'extension' => [
                'nombEntrega' => $entrega['nombEntrega'], 'docuEntrega' => $entrega['docuEntrega'], 'codEmpleado' => $entrega['codEmpleado'] ?? null,
            ],
            'apendice' => null,
        ];

        return new DteArmado('09', 2, $codigo, $numeroControl, [
            'subTotal' => $subTotal, 'ivaPercibido' => $ivaPercibido, 'ivaComision' => $ivaComision, 'liquidoApagar' => $liquido,
        ], DteJson::encode($documento));
    }
}
