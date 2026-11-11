<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Contingencia (Normativa 13.2): reintentar al menos cada 15 minutos.
Schedule::command('dte:contingencia:procesar')->everyFifteenMinutes()->withoutOverlapping();
