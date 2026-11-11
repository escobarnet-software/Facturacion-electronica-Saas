<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Excepciones\DteException;
use App\Support\Decimal;

/** Comprobante de Crédito Fiscal (03, esquema v4): precio SIN IVA, IVA desglosado. */
final class CreditoFiscalBuilder extends BuilderContribuyente
{
    protected function tipoDte(): string
    {
        return '03';
    }

    protected function version(): int
    {
        return 4;
    }

    protected function secciones(DocumentoContribuyente $doc, CalculoContribuyente $c): array
    {
        if ($doc->receptor->nrc === null) {
            throw new DteException('El Crédito Fiscal se emite a contribuyentes inscritos en IVA: falta el NRC del cliente.');
        }
        $r = $doc->receptor;
        $cero = Decimal::zero();

        $cuerpo = [];
        foreach ($c->items as $i => $item) {
            $l = $item['linea'];
            $cuerpo[] = [
                'numItem' => $i + 1,
                'tipoItem' => $l->tipoItem,
                'numeroDocumento' => $l->numeroDocumento,
                'codigo' => $l->codigo,
                'codTributo' => $l->tipoItem === 4 ? $l->codTributo : null,
                'descripcion' => $l->descripcion,
                'cantidad' => $item['cantidad'],
                'uniMedida' => $l->uniMedida,
                'precioUni' => $item['precioUni'],
                'montoDescu' => $item['montoDescu'],
                'ventaNoSuj' => $item['ventaNoSuj'],
                'ventaExenta' => $item['ventaExenta'],
                'ventaGravada' => $item['ventaGravada'],
                'tributos' => $this->tributosItem($item),
                'psv' => $cero,
                'noGravado' => $item['noGravado'],
            ];
        }

        return [
            'documentoRelacionado' => $this->relacionados($doc),
            'emisor' => $this->emisorConEstablecimiento(),
            'receptor' => [
                'nit' => $r->nit,
                'nrc' => $r->nrc,
                'nombre' => $r->nombre,
                'codActividad' => $r->codActividad,
                'descActividad' => $r->descActividad,
                'nombreComercial' => $r->nombreComercial,
                'direccion' => $r->direccion(),
                'telefono' => $r->telefono,
                'correo' => $r->correo,
            ],
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => $c->totalNoSuj,
                'totalExenta' => $c->totalExenta,
                'totalGravada' => $c->totalGravada,
                'subTotalVentas' => $c->subTotalVentas,
                'descuNoSuj' => $c->descuNoSuj,
                'descuExenta' => $c->descuExenta,
                'descuGravada' => $c->descuGravada,
                'porcentajeDescuento' => $c->porcentajeDescuento,
                'totalDescu' => $c->totalDescu,
                'tributos' => $this->tributosResumen($c),
                'subTotal' => $c->subTotal,
                'ivaPerci' => $c->ivaPerci,
                'ivaRete' => $c->ivaRete,
                'montoTotalOperacion' => $c->montoTotalOperacion,
                'totalNoGravado' => $c->totalNoGravado,
                'totalPagar' => $c->totalPagar,
                'totalLetras' => $this->letras($c->totalPagar),
                'saldoFavor' => $cero,
                'condicionOperacion' => $doc->condicionOperacion,
                'pagos' => $this->pagos($doc->pagos, $c->totalPagar),
                'numPagoElectronico' => null,
                'observaciones' => $doc->observaciones,
            ],
            'apendice' => null,
        ];
    }
}
