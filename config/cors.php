<?php

/*
| CORS solo para la API pública. Los POS y ERP llaman a la API desde su servidor (no necesitan CORS);
| si un cliente necesita llamarla desde un navegador, se agrega su dominio en CORS_ORIGENES.
| La API usa tokens en la cabecera Authorization, nunca cookies: sin credenciales cruzadas.
*/

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ORIGENES', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key'],
    'exposed_headers' => ['Idempotent-Replayed'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
