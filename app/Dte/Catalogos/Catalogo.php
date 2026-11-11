<?php

declare(strict_types=1);

namespace App\Dte\Catalogos;

/**
 * Catálogos oficiales más usados, copiados del Excel
 * "Catálogos – Facturación Electrónica" del Ministerio de Hacienda (julio 2026).
 *
 * Los catálogos largos (CAT-008 Distrito, CAT-013 Municipio, CAT-019 Actividad
 * económica, CAT-020 País) deben cargarse desde el archivo oficial a la base de
 * datos; no se escriben a mano aquí.
 */
final class Catalogo
{
    /** CAT-001 Ambiente de destino */
    public const AMBIENTE = [
        '00' => 'Modo prueba',
        '01' => 'Modo producción',
    ];

    /** CAT-002 Tipo de documento / evento */
    public const TIPO_DTE = [
        '01' => 'Factura',
        '03' => 'Comprobante de crédito fiscal',
        '04' => 'Nota de remisión',
        '05' => 'Nota de crédito',
        '06' => 'Nota de débito',
        '07' => 'Comprobante de retención',
        '08' => 'Comprobante de liquidación',
        '09' => 'Documento contable de liquidación',
        '11' => 'Facturas de exportación',
        '14' => 'Factura de sujeto excluido',
        '15' => 'Comprobante de donación',
        '17' => 'Evento de Operaciones Especiales',
        '18' => 'Evento de Retorno',
    ];

    /** CAT-003 Modelo de facturación */
    public const MODELO_FACTURACION = [
        1 => 'Modelo Facturación previo',
        2 => 'Modelo Facturación diferido',
    ];

    /** CAT-004 Tipo de transmisión */
    public const TIPO_TRANSMISION = [
        1 => 'Transmisión normal',
        2 => 'Transmisión por contingencia',
    ];

    /** CAT-005 Tipo de contingencia */
    public const TIPO_CONTINGENCIA = [
        1 => 'No disponibilidad de sistema del MH',
        2 => 'No disponibilidad de sistema del emisor',
        3 => 'Falla en el suministro de servicio de Internet del Emisor',
        4 => 'Falla en el suministro de servicio de energía eléctrica del emisor que impida la transmisión de los DTE',
        5 => 'Otro',
    ];

    /** CAT-011 Tipo de ítem */
    public const TIPO_ITEM = [
        1 => 'Bienes',
        2 => 'Servicios',
        3 => 'Ambos (Bienes y Servicios)',
        4 => 'Otros tributos por ítem',
    ];

    /** CAT-012 Departamento */
    public const DEPARTAMENTO = [
        '00' => 'Otro (Para extranjeros)',
        '01' => 'Ahuachapán', '02' => 'Santa Ana', '03' => 'Sonsonate', '04' => 'Chalatenango',
        '05' => 'La Libertad', '06' => 'San Salvador', '07' => 'Cuscatlán', '08' => 'La Paz',
        '09' => 'Cabañas', '10' => 'San Vicente', '11' => 'Usulután', '12' => 'San Miguel',
        '13' => 'Morazán', '14' => 'La Unión',
    ];

    /** CAT-014 Unidad de medida (las más usadas; la lista completa está en el Excel oficial) */
    public const UNIDAD_MEDIDA = [
        1 => 'metro', 13 => 'metro cuadrado', 22 => 'Galón', 23 => 'Litro', 34 => 'Kilogramo',
        36 => 'Libra', 39 => 'Gramo', 58 => 'Docena', 59 => 'Unidad', 99 => 'Otra',
    ];

    /** CAT-016 Condición de la operación */
    public const CONDICION_OPERACION = [
        1 => 'Contado',
        2 => 'A crédito',
        3 => 'Otro',
    ];

    /** CAT-017 Forma de pago */
    public const FORMA_PAGO = [
        '01' => 'Billetes y monedas',
        '02' => 'Tarjeta Débito',
        '03' => 'Tarjeta Crédito',
        '04' => 'Cheque',
        '05' => 'Transferencia-Depósito Bancario',
        '08' => 'Dinero electrónico',
        '09' => 'Monedero electrónico',
        '11' => 'Bitcoin',
        '12' => 'Otras Criptomonedas',
        '13' => 'Cuentas por pagar del receptor',
        '14' => 'Giro bancario',
        '99' => 'Otros (se debe indicar el medio de pago)',
    ];

    /** CAT-022 Tipo de documento de identificación */
    public const TIPO_DOC_IDENTIFICACION = [
        '36' => 'NIT',
        '13' => 'DUI',
        '37' => 'Otro',
        '03' => 'Pasaporte',
        '02' => 'Carnet de Residente',
    ];

    /** CAT-024 Motivo del evento (invalidación) */
    public const MOTIVO_INVALIDACION = [
        1 => 'Error en la Información del Documento Tributario Electrónico a invalidar.',
        2 => 'Rescindir de la operación realizada.',
        3 => 'Otro',
    ];

    /** @param  array<int|string, string>  $catalogo */
    public static function existe(array $catalogo, string|int $codigo): bool
    {
        return array_key_exists($codigo, $catalogo);
    }
}
