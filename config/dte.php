<?php

/*
|--------------------------------------------------------------------------
| Configuración del motor DTE de Escobar Net
|--------------------------------------------------------------------------
| Valores tomados del "Manual Técnico para la Integración Tecnológica del
| Sistema de Transmisión v2" (MTIST) y de la Normativa de Cumplimiento DTE v2.0.
| Base: config/dte.php del Paso 1 (escobarnet-dte-paso1), ampliado en el Paso 0.
|
| Las credenciales de cada empresa (contraseña API, clave privada, certificado)
| NO van aquí: se guardan cifradas en la base de datos, una por empresa.
| Las URL de Hacienda salen SOLO de este archivo; nunca se escriben a mano.
*/

return [

    // saas = multiempresa, dedicado = una sola empresa. Mismo código.
    'modo' => env('APP_MODE', 'saas'),

    // "00" = pruebas, "01" = producción. Nunca enviar DTE de prueba a producción.
    // Es el valor por defecto; cada empresa tiene su propio ambiente.
    'ambiente' => env('DTE_AMBIENTE', '00'),

    'api' => [
        'base_url' => [
            '00' => 'https://apitest.dtes.mh.gob.sv',
            '01' => 'https://api.dtes.mh.gob.sv',
        ],
        // Las rutas van exactas y SIN barra al final (MTIST, 6.2).
        'rutas' => [
            'autenticacion' => '/seguridad/auth',
            'recepcion' => '/fesv/recepciondte',
            'recepcion_lote' => '/fesv/recepcionlote',
            'consulta' => '/fesv/recepcion/consultadte',
            'consulta_lote' => '/fesv/recepcion/consultadtelote/{codigoLote}',
            'contingencia' => '/fesv/contingencia',
            'invalidacion' => '/fesv/anulardte',
        ],
        'user_agent' => env('DTE_USER_AGENT', 'EscobarNet-DTE/1.0'),

        // Política de reintentos (MTIST): esperar 8 s; si no hay respuesta,
        // consultar y reenviar hasta 2 veces; luego, contingencia.
        'timeout_segundos' => 8,
        'reintentos' => 2,

        // Vigencia del token: 48 h en pruebas, 24 h en producción.
        // Se guarda en caché una hora menos, para renovarlo antes de que venza.
        'token_horas' => ['00' => 47, '01' => 23],

        // 3 logins fallidos bloquean al usuario API del cliente: nunca en bucle.
        'max_logins_fallidos' => 3,
    ],

    'lote' => [
        // Hasta 100 DTE por lote (MTIST).
        'max_documentos' => 100,
    ],

    'contingencia' => [
        // Reintentar la conexión al menos cada 15 minutos.
        'reintento_minutos' => 15,
        // El evento de contingencia se transmite dentro de 24 h desde el cese.
        'evento_horas' => 24,
        // Los documentos, dentro de 72 h desde el sello del evento.
        'transmision_horas' => 72,
        // Más de 3 días consecutivos exige Informe Técnico.
        'informe_dias' => 3,
    ],

    'firmador' => [
        // Firmador oficial de Hacienda (svfe-api-firmador) en la red interna.
        'url' => env('DTE_FIRMADOR_URL', 'http://firmador:8113/firmardocumento/'),
        'timeout_segundos' => 10,
        // Disco privado donde viven los certificados {NIT}.crt (montado en /uploads).
        'disco_certificados' => 'certificados',
    ],

    // Consulta pública (para el QR de la representación gráfica).
    'consulta_publica_url' => 'https://admin.factura.gob.sv/consultaPublica?ambiente={ambiente}&codGen={codigoGeneracion}&fechaEmi={fechaEmi}',

    // Carpeta con los JSON Schemas oficiales (descargados de factura.gob.sv).
    'esquemas' => resource_path('schemas'),

    // Llave dedicada para cifrar los secretos de los clientes (separada de APP_KEY).
    'secrets_key' => env('DTE_SECRETS_KEY'),

    'zona_horaria' => 'America/El_Salvador',

    // codigoMsg de Hacienda → mensaje simple en español.
    'mensajes_mh' => require __DIR__.'/dte/mensajes_mh.php',
];
