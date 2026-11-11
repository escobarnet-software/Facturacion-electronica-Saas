<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet: ni a Hacienda (tampoco a apitest) ni al firmador.
        // Toda petición HTTP debe estar simulada con Http::fake; si no, la prueba falla.
        Http::preventStrayRequests();
    }
}
