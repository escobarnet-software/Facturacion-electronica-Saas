<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('usa PostgreSQL como base de datos, también en las pruebas', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $version = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
    expect($version)->toBeGreaterThanOrEqual(160000);
});

it('maneja montos exactos con NUMERIC en PostgreSQL', function () {
    $fila = DB::selectOne('SELECT (0.1::numeric + 0.2::numeric)::text AS suma, (99999999999.12345678::numeric(20,8))::text AS grande');

    expect($fila->suma)->toBe('0.3')
        ->and($fila->grande)->toBe('99999999999.12345678');
});

it('se conecta a Redis', function () {
    $clave = 'prueba:paso0:'.bin2hex(random_bytes(4));
    Redis::connection()->set($clave, 'ok', 'EX', 10);

    expect(Redis::connection()->get($clave))->toBe('ok');
    Redis::connection()->del($clave);
});

it('trabaja con la hora de El Salvador', function () {
    expect(config('app.timezone'))->toBe('America/El_Salvador')
        ->and(now()->getTimezone()->getName())->toBe('America/El_Salvador');
});

it('tiene la configuración DTE del MTIST', function () {
    expect(config('dte.ambiente'))->toBe('00')
        ->and(config('dte.modo'))->toBeIn(['saas', 'dedicado'])
        ->and(config('dte.api.base_url'))->toBe([
            '00' => 'https://apitest.dtes.mh.gob.sv',
            '01' => 'https://api.dtes.mh.gob.sv',
        ])
        ->and(config('dte.api.timeout_segundos'))->toBe(8)
        ->and(config('dte.api.reintentos'))->toBe(2)
        ->and(config('dte.api.token_horas'))->toBe(['00' => 47, '01' => 23])
        ->and(config('dte.api.max_logins_fallidos'))->toBe(3)
        ->and(config('dte.lote.max_documentos'))->toBe(100)
        ->and(config('dte.contingencia.reintento_minutos'))->toBe(15);

    foreach (config('dte.api.rutas') as $ruta) {
        expect($ruta)->toStartWith('/')->not->toEndWith('/');
    }
});

it('tiene los 15 esquemas JSON oficiales de Hacienda', function () {
    $esperados = [
        'fe-f-v2', 'fe-ccf-v4', 'fe-nr-v4', 'fe-nc-v4', 'fe-nd-v4', 'fe-cr-v2',
        'fe-cl-v2', 'fe-dcl-v2', 'fe-fex-v3', 'fe-fse-v2', 'fe-cd-v2',
        'invalidacion-schema-v3', 'contingencia-schema-v4', 'fe-eret-v1', 'fe-eop-v1',
    ];

    foreach ($esperados as $nombre) {
        $ruta = config('dte.esquemas')."/{$nombre}.json";
        expect($ruta)->toBeFile();
        expect(json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKey('properties');
    }
});

it('responde el healthcheck', function () {
    $this->get('/up')->assertOk();
});
