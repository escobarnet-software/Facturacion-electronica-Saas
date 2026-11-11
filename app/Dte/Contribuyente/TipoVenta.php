<?php

namespace App\Dte\Contribuyente;

/** En qué columna del cuerpo va una línea (un ítem va en una sola columna). */
enum TipoVenta: string
{
    case Gravada = 'gravada';
    case Exenta = 'exenta';
    case NoSujeta = 'no_sujeta';
    /** Cargos o abonos que no afectan la base imponible (propinas, pagos no afectos): campo noGravado. */
    case NoAfecta = 'no_afecta';
}
