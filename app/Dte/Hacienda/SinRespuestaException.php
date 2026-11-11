<?php

namespace App\Dte\Hacienda;

/** Hacienda no respondió a tiempo (timeout de 8 s) o hubo un error de red. */
final class SinRespuestaException extends HaciendaException {}
