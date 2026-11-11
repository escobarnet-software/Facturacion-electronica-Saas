<?php

declare(strict_types=1);

namespace App\Dte\Excepciones;

use RuntimeException;

/**
 * Error de negocio del motor DTE, con un mensaje que se le puede mostrar
 * al cajero tal cual (en español y sin términos técnicos).
 */
final class DteException extends RuntimeException {}
