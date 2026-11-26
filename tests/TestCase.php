<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * La base de pruebas se crea con los catálogos oficiales de Hacienda (docs/oficial/Catalogos.xlsx):
     * las validaciones de los formularios los consultan (actividades, departamentos, municipios…).
     * Con RefreshDatabase se siembran una sola vez por ejecución.
     */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet: ni a Hacienda (tampoco a apitest) ni al firmador.
        // Toda petición HTTP debe estar simulada con Http::fake; si no, la prueba falla.
        Http::preventStrayRequests();

        // Las pruebas no necesitan los assets compilados (npm run build).
        $this->withoutVite();
    }
}
