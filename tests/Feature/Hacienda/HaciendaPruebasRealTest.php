<?php

use App\Dte\Credenciales\AlmacenCertificados;
use App\Dte\Hacienda\TransmisorDte;
use App\Enums\EstadoDte;
use App\Models\EmpresaCredencial;

/*
| Prueba REAL contra el ambiente de pruebas de Hacienda (grupo hacienda-pruebas).
| Excluida por defecto. Necesita, en el .env del desarrollador (nunca en el repositorio):
|   HACIENDA_PRUEBAS_NIT, HACIENDA_PRUEBAS_PWD_API, HACIENDA_PRUEBAS_PWD_PRIVADA
| y el certificado de pruebas en storage/app/private/certificados/{NIT}.crt, con el firmador arriba.
| Ejecutar:  php artisan test --group=hacienda-pruebas
*/

it('obtiene un sello real de Hacienda para una Factura (01)', function () {
    $nit = (string) env('HACIENDA_PRUEBAS_NIT');
    if ($nit === '' || env('HACIENDA_PRUEBAS_PWD_API') === null || env('HACIENDA_PRUEBAS_PWD_PRIVADA') === null) {
        $this->markTestSkipped('Faltan HACIENDA_PRUEBAS_* en el .env: prueba real pendiente de credenciales.');
    }

    $d = crearEmpresaDePrueba();
    tenancy()->sinAislamiento(fn () => $d['empresa']->forceFill(['nit' => $nit])->save());
    tenancy()->establecer($d['empresa']->id);

    $credencial = EmpresaCredencial::query()->firstOrFail();
    $credencial->forceFill(['usuario_api' => $nit])
        ->establecerSecretos((string) env('HACIENDA_PRUEBAS_PWD_API'), (string) env('HACIENDA_PRUEBAS_PWD_PRIVADA'))
        ->save();
    expect(app(AlmacenCertificados::class)->existe($d['empresa']->fresh()))->toBeTrue('Falta el certificado {NIT}.crt');

    $dte = crearDteDePrueba(['empresa' => $d['empresa']->fresh(), 'establecimiento' => $d['establecimiento'], 'puntoVenta' => $d['puntoVenta']]);
    $resultado = TransmisorDte::crear()->procesar($dte, $d['empresa']->fresh(), $credencial);

    expect($resultado->estado)->toBeIn([EstadoDte::Transmitido, EstadoDte::Observado])
        ->and($resultado->sello_recibido)->toHaveLength(40);
})->group('hacienda-pruebas');
