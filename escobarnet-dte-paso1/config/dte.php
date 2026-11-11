<?php

/*
|--------------------------------------------------------------------------
| Configuración del motor DTE de Escobar Net
|--------------------------------------------------------------------------
| Valores tomados del "Manual Tecnológico para la Integración del Sistema
| de Transmisión V2.0" (Ministerio de Hacienda, mayo 2026).
|
| Las credenciales de cada negocio (contraseña API, clave privada del
| certificado) NO van aquí: se guardan cifradas en la base de datos, una por
| cliente. Este archivo solo tiene la configuración general del motor.
*/

return [

    // "00" = pruebas, "01" = producción. Nunca enviar DTE de prueba a producción.
    'ambiente' => env('DTE_AMBIENTE', '00'),

    'api' => [
        'base_url' => [
            '00' => 'https://apitest.dtes.mh.gob.sv',
            '01' => 'https://api.dtes.mh.gob.sv',
        ],
        // Las rutas van exactas y SIN barra al final (Manual Tecnológico, 6.2).
        'rutas' => [
            'autenticacion' => '/seguridad/auth',
            'recepcion' => '/fesv/recepciondte',
            'recepcion_lote' => '/fesv/recepcionlote',
            'consulta' => '/fesv/recepcion/consultadte',
            'consulta_lote' => '/fesv/recepcion/consultadtelote/{codigoLote}',
            'contingencia' => '/fesv/contingencia',
            'invalidacion' => '/fesv/anulardte',
        ],
        'user_agent' => env('DTE_USER_AGENT', 'EscobarNet-MotorDTE/1.0'),

        // Política de reintentos: esperar 8 segundos; si no hay respuesta,
        // consultar y reenviar hasta 2 veces; luego, contingencia.
        'timeout_segundos' => 8,
        'reintentos' => 2,

        // Vigencia del token: 48 h en pruebas, 24 h en producción.
        // Se renueva un poco antes para no quedar a medias.
        'token_horas' => ['00' => 47, '01' => 23],
    ],

    'firmador' => [
        // Firmador oficial de Hacienda (svfe-api-firmador) corriendo en Docker.
        'url' => env('DTE_FIRMADOR_URL', 'http://localhost:8113/firmardocumento/'),
        'timeout_segundos' => 10,
    ],

    // Carpeta con los JSON Schemas oficiales (descargados de factura.gob.sv).
    'esquemas' => storage_path('app/dte/schemas'),

    'zona_horaria' => 'America/El_Salvador',
];
