<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

/** Nota de Débito (06, v4): aumenta un CCF. */
final class NotaDebitoBuilder extends AjusteBuilder
{
    protected function tipoDte(): string
    {
        return '06';
    }

    protected function resumen(DocumentoContribuyente $doc, CalculoContribuyente $c): array
    {
        $resumen = parent::resumen($doc, $c);
        $posicion = array_search('observaciones', array_keys($resumen), true);

        // La ND v4 agrega numPagoElectronico antes de las observaciones.
        return array_slice($resumen, 0, (int) $posicion, true)
            + ['numPagoElectronico' => null]
            + array_slice($resumen, (int) $posicion, null, true);
    }
}
