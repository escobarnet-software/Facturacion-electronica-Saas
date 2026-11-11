<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Excepciones\DteException;

/**
 * Base de la Nota de Crédito (05) y la Nota de Débito (06), esquema v4.
 *
 * - Ajustan un CCF (03) o un Comprobante de Retención (07) con sello: documentoRelacionado es obligatorio.
 * - Cada ítem indica el documento que ajusta (numeroDocumento).
 * - Sin descuento global: el Manual Funcional (XVIII) pide detallarlo en el descuento por ítem.
 * - Llevan IVA, percepción y retención por ítem, y la condición de la operación va sin pagos.
 */
abstract class AjusteBuilder extends BuilderContribuyente
{
    private const TIPOS_AJUSTABLES = ['03', '07'];

    /** CAT-006: retención IVA 1 %, 13 % y otras. */
    private const CODIGOS_RETENCION = ['22', 'C4', 'C9'];

    protected function version(): int
    {
        return 4;
    }

    protected function impuestosPorItem(): bool
    {
        return true;
    }

    protected function identificacionExtra(): array
    {
        return ['fusion' => null];
    }

    protected function validarComun(DocumentoContribuyente $doc): void
    {
        parent::validarComun($doc);
        $nombre = $this->tipoDte() === '05' ? 'La Nota de Crédito' : 'La Nota de Débito';

        if ($doc->relacionados === []) {
            throw new DteException("{$nombre} debe indicar el documento que ajusta (documento relacionado).");
        }
        $numeros = [];
        foreach ($doc->relacionados as $rel) {
            if (! in_array($rel->tipoDocumento, self::TIPOS_AJUSTABLES, true)) {
                throw new DteException("{$nombre} solo ajusta Comprobantes de Crédito Fiscal (03) o de Retención (07).");
            }
            $numeros[] = $rel->numeroDocumento;
        }
        foreach (array_values($doc->lineas) as $i => $linea) {
            if ($linea->numeroDocumento === null || ! in_array($linea->numeroDocumento, $numeros, true)) {
                throw new DteException('Línea '.($i + 1).': debe indicar cuál de los documentos relacionados ajusta.');
            }
        }
        $hayGlobal = array_filter($doc->descuentosGlobales, fn ($d) => $d !== null && ! $d->isZero()) !== [];
        if ($hayGlobal || ($doc->porcentajeDescuento !== null && ! $doc->porcentajeDescuento->isZero())) {
            throw new DteException("En {$nombre} el descuento global se detalla dentro del descuento de cada línea.");
        }
        if ($doc->codigoRetencionMH !== null && ! in_array($doc->codigoRetencionMH, self::CODIGOS_RETENCION, true)) {
            throw new DteException("Código de retención inválido: {$doc->codigoRetencionMH} (CAT-006).");
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
                'cantidad' => $item['cantidad'],
                'codigo' => $l->codigo,
                'codTributo' => $l->tipoItem === 4 ? $l->codTributo : null,
                'uniMedida' => $l->uniMedida,
                'descripcion' => $l->descripcion,
                'precioUni' => $item['precioUni'],
                'montoDescu' => $item['montoDescu'],
                'ventaNoSuj' => $item['ventaNoSuj'],
                'ventaExenta' => $item['ventaExenta'],
                'ventaGravada' => $item['ventaGravada'],
                'tributos' => $this->tributosItem($item),
                'noGravado' => $item['noGravado'],
                'ivaPerci' => $item['ivaPerci'],
                'totalIva' => $item['totalIva'],
                'ivaRete' => $item['ivaRete'],
            ];
        }

        return [
            'documentoRelacionado' => $this->relacionados($doc),
            'emisor' => $this->emisorBase(),
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
            ],
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => $this->resumen($doc, $c),
            'apendice' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function resumen(DocumentoContribuyente $doc, CalculoContribuyente $c): array
    {
        return [
            'totalNoSuj' => $c->totalNoSuj,
            'totalExenta' => $c->totalExenta,
            'totalGravada' => $c->totalGravada,
            'subTotalVentas' => $c->subTotalVentas,
            'totalDescu' => $c->totalDescu,
            'tributos' => $this->tributosResumen($c),
            'montoTotalOperacion' => $c->montoTotalOperacion,
            'ivaPerci' => $c->ivaPerci,
            'totalIva' => $c->iva,
            'ivaRete' => $c->ivaRete,
            'totalNoGravado' => $c->totalNoGravado,
            'totalPagar' => $c->totalPagar,
            'totalLetras' => $this->letras($c->totalPagar),
            'condicionOperacion' => $doc->condicionOperacion,
            'observaciones' => $doc->observaciones,
            'codigoRetencionMH' => $doc->codigoRetencionMH,
        ];
    }
}
