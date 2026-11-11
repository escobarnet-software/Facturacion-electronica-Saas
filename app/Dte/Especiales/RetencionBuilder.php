<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Support\NumeroALetras;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeImmutable;

/**
 * Comprobante de Retención (07, v2). El emisor es el agente de retención; el receptor, el sujeto retenido.
 * Cada ítem referencia un documento retenido.
 *
 *   ivaRetenido = montoSujetoGrav × tasa del CAT-006 (22 → 1 %, C4 → 13 %); C9 (otros casos): monto indicado.
 *   totalIva («IVA generado 13 %») = Σ montoSujetoGrav × 13 %.
 */
final class RetencionBuilder
{
    use ArmaDte;

    private const TASAS = ['22' => '0.01', 'C4' => '0.13'];

    /**
     * @param  list<array{tipoDte: string, numeroDocumento: string, fechaEmision: string, montoSujetoGrav: string, codigoRetencionMH: string, descripcion?: string|null, tipoGeneracion?: int, ivaRetenido?: string}>  $documentos
     */
    public function construir(
        Emisor $emisor,
        ReceptorContribuyente $sujeto,
        array $documentos,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        ?string $observaciones = null,
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($documentos === [] || count($documentos) > 500) {
            throw new DteException('El comprobante de retención debe tener entre 1 y 500 documentos retenidos.');
        }

        $cuerpo = [];
        $sujetoTotal = Decimal::zero();
        $retenido = Decimal::zero();
        foreach (array_values($documentos) as $i => $d) {
            $n = $i + 1;
            $monto = Decimal::of($d['montoSujetoGrav'])->round(2);
            if (! $monto->isPositive()) {
                throw new DteException("Documento {$n}: el monto sujeto a retención debe ser mayor que cero.");
            }
            $codigoRet = $d['codigoRetencionMH'];
            if (isset(self::TASAS[$codigoRet])) {
                $iva = $monto->mul(self::TASAS[$codigoRet])->round(2);
            } elseif ($codigoRet === 'C9' && isset($d['ivaRetenido'])) {
                $iva = Decimal::of($d['ivaRetenido'])->round(2);
            } else {
                throw new DteException("Documento {$n}: código de retención inválido (CAT-006: 22, C4 o C9 con monto).");
            }
            if (! $iva->isPositive()) {
                throw new DteException("Documento {$n}: el IVA retenido debe ser mayor que cero.");
            }
            $sujetoTotal = $sujetoTotal->add($monto);
            $retenido = $retenido->add($iva);
            $cuerpo[] = [
                'numItem' => $n, 'tipoDte' => $d['tipoDte'], 'tipoGeneracion' => $d['tipoGeneracion'] ?? 2,
                'numeroDocumento' => $d['numeroDocumento'], 'fechaEmision' => $d['fechaEmision'],
                'montoSujetoGrav' => $monto, 'codigoRetencionMH' => $codigoRet, 'ivaRetenido' => $iva,
                'descripcion' => $d['descripcion'] ?? null,
            ];
        }
        $totalIva = $sujetoTotal->mul('0.13')->round(2);

        [$identificacion, $numeroControl, $codigo] = $this->identificacion('07', 2, $ambiente, $emisor, $correlativo, $fechaHora, $codigoGeneracion);
        unset($identificacion['fusion']);

        $documento = [
            'identificacion' => $identificacion,
            'emisor' => [
                'nit' => $emisor->nit, 'nrc' => $emisor->nrc, 'nombre' => $emisor->nombre,
                'codActividad' => $emisor->codActividad, 'descActividad' => $emisor->descActividad,
                'nombreComercial' => $emisor->nombreComercial, 'direccion' => $this->direccionEmisor($emisor),
                'codEstable' => $emisor->codEstable, 'codPuntoVenta' => $emisor->codPuntoVenta,
                'telefono' => $emisor->telefono, 'correo' => $emisor->correo,
            ],
            'receptor' => [
                'tipoDocumento' => $sujeto->tipoDocumento, 'numDocumento' => $sujeto->nit, 'nrc' => $sujeto->nrc,
                'nombre' => $sujeto->nombre, 'codActividad' => $sujeto->codActividad, 'descActividad' => $sujeto->descActividad,
                'nombreComercial' => $sujeto->nombreComercial, 'direccion' => $sujeto->direccion(),
                'telefono' => $sujeto->telefono, 'correo' => $sujeto->correo,
            ],
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalSujetoRetencion' => $sujetoTotal,
                'totalIva' => $totalIva,
                'totalIvaRetenido' => $retenido,
                'totalLetras' => NumeroALetras::convertir($retenido),
                'observaciones' => $observaciones,
            ],
            'apendice' => null,
        ];

        return new DteArmado('07', 2, $codigo, $numeroControl, [
            'totalSujetoRetencion' => $sujetoTotal, 'totalIva' => $totalIva, 'totalIvaRetenido' => $retenido,
        ], DteJson::encode($documento));
    }
}
