<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Contingencia (Normativa 13.2): reintentar al menos cada 15 minutos.
Schedule::command('dte:contingencia:procesar')->everyFifteenMinutes()->withoutOverlapping();

// Cobro de suscripciones: el día 1 de cada mes, por el mes anterior (hora de El Salvador).
Schedule::command('plataforma:facturar')->monthlyOn(1, '06:17')->timezone('America/El_Salvador')->withoutOverlapping();
