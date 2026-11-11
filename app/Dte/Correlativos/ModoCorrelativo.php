<?php

namespace App\Dte\Correlativos;

/**
 * Cómo se numeran los documentos de una empresa (columna empresas.modo_correlativo).
 *
 * TODO(normativa): la Normativa v2.0 (7.1.2) admite correlativo «centralizado» o «por
 * establecimiento», no por punto de venta ni por tipo sin resolución; el mercado usa
 * «por tipo». Decisión pendiente nº 1 de PLAN.md: confirmar con Hacienda en pruebas.
 */
enum ModoCorrelativo: string
{
    /** Una secuencia por tipo de DTE, establecimiento y punto de venta (práctica del mercado; por defecto). */
    case PorTipo = 'por_tipo';

    /** Una secuencia por establecimiento, compartida por todos sus tipos y puntos de venta. */
    case PorEstablecimiento = 'por_establecimiento';

    /** Una sola secuencia para toda la empresa. */
    case Centralizado = 'centralizado';
}
