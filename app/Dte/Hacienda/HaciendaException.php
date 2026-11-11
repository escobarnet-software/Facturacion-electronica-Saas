<?php

namespace App\Dte\Hacienda;

use RuntimeException;

/** Error al hablar con la API de Hacienda. El mensaje es apto para el cliente (en español). */
class HaciendaException extends RuntimeException {}
