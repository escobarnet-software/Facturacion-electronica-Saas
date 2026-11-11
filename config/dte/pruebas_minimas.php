<?php

/*
| Pruebas de transmisión satisfactorias mínimas para ser autorizado como emisor
| (Manual Funcional del Sistema de Transmisión v2.0, sección XXIV).
| Solo se ejecutan los tipos que la empresa va a usar.
*/

return [
    'documentos' => [
        '01' => 90, // Factura
        '03' => 75, // Comprobante de Crédito Fiscal
        '04' => 50, // Nota de Remisión
        '05' => 50, // Nota de Crédito
        '06' => 25, // Nota de Débito
        '07' => 50, // Comprobante de Retención
        '08' => 75, // Comprobante de Liquidación
        '09' => 50, // Documento Contable de Liquidación
        '11' => 90, // Factura de Exportación
        '14' => 25, // Factura de Sujeto Excluido
        '15' => 25, // Comprobante de Donación
    ],
    'eventos' => [
        'invalidacion' => 5,
        'contingencia' => 5,
        'retorno' => 5,
        'operaciones_especiales' => 5,
    ],
    // Tras estos rechazos seguidos se detiene la ejecución (para revisar antes de insistir).
    'max_fallos_seguidos' => 5,
];
