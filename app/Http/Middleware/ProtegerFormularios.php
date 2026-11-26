<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Protección CSRF y de origen de Laravel, sin la cookie XSRF-TOKEN legible por JavaScript:
 * los formularios y Livewire envían el token desde la página, así que esa cookie no hace falta
 * y una cookie sin HttpOnly sería un dato más expuesto ante un XSS.
 */
final class ProtegerFormularios extends PreventRequestForgery
{
    protected $addHttpCookie = false;
}
