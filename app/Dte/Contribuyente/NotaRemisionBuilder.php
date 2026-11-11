<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Excepciones\DteException;

/**
 * Nota de Remisión (04, v4): traslado de bienes sin venta (consignación, traslado
 * entre bodegas…). El receptor indica el título a que se remiten los bienes (CAT-025).
 * Puede no tener valor de venta; no lleva pagos, retención, percepción ni montos no afectos.
 */
final class NotaRemisionBuilder extends BuilderContribuyente
{
    private const TITULOS = ['01', '02', '03', '04', '05'];

    protected function tipoDte(): string
    {
        return '04';
    }

    protected function version(): int
    {
        return 4;
    }

    protected function validarComun(DocumentoContribuyente $doc): void
    {
        parent::validarComun($doc);

        if ($doc->bienTitulo === null || ! in_array($doc->bienTitulo, self::TITULOS, true)) {
            throw new DteException('La Nota de Remisión debe indicar a qué título se remiten los bienes (CAT-025).');
        }
        if ($doc->retenerIva1 || $doc->percibirIva1) {
            throw new DteException('La Nota de Remisión no lleva retención ni percepción de IVA.');
        }
        foreach ($doc->lineas as $linea) {
            if ($linea->tipoVenta === TipoVenta::NoAfecta) {
                throw new DteException('La Nota de Remisión no admite montos no afectos.');
            }
        }
    }

    protected function secciones(DocumentoContribuyente $doc, CalculoContribuyente $c): array
    {
        $r = $doc->receptor;

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
            ];
        }

        return [
            'documentoRelacionado' => $this->relacionados($doc),
            'emisor' => $this->emisorConEstablecimiento(),
            'receptor' => [
                'tipoDocumento' => $r->tipoDocumento,
                'numDocumento' => $r->nit,
                'nrc' => $r->nrc,
                'nombre' => $r->nombre,
                'codActividad' => $r->codActividad,
                'descActividad' => $r->descActividad,
                'nombreComercial' => $r->nombreComercial,
                'direccion' => $r->direccion(),
                'telefono' => $r->telefono,
                'correo' => $r->correo,
                'bienTitulo' => $doc->bienTitulo,
            ],
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
                'montoTotalOperacion' => $c->montoTotalOperacion,
                'totalLetras' => $this->letras($c->montoTotalOperacion),
                'observaciones' => $doc->observaciones,
            ],
            'apendice' => null,
        ];
    }
}
