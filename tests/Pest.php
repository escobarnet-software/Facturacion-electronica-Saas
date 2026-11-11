<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest
|--------------------------------------------------------------------------
| Las pruebas de Feature usan la aplicación Laravel completa con PostgreSQL
| (nunca SQLite: hacen falta RLS y NUMERIC).
*/

pest()->extend(TestCase::class)->in('Feature');
