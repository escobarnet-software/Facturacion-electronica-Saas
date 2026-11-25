<?php

/*
| Operación de la plataforma Escobar Net (superadmin, planes y cobros).
*/

return [
    // Empresa de Escobar Net dentro de la plataforma: emite los DTE de cobro a sus clientes.
    'empresa_id' => env('PLATAFORMA_EMPRESA_ID') !== null ? (int) env('PLATAFORMA_EMPRESA_ID') : null,

    // Duración máxima del permiso de soporte que una empresa puede otorgar.
    'soporte_horas_max' => 72,

    // Pasarela de cobro. TODO(decision-3): pendiente; por ahora solo pagos registrados a mano.
    'pasarela' => 'manual',
];
