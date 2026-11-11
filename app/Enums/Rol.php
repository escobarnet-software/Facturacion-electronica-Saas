<?php

namespace App\Enums;

/** Rol de un usuario dentro de una empresa (ESPECIFICACION §1.3). */
enum Rol: string
{
    case Dueno = 'dueno';
    case Contador = 'contador';
    case Cajero = 'cajero';
    case Consulta = 'consulta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Dueno => 'Dueño',
            self::Contador => 'Contador',
            self::Cajero => 'Cajero',
            self::Consulta => 'Consulta',
        };
    }

    /** El doble factor es obligatorio para dueños (y para el superadmin). */
    public function exige2fa(): bool
    {
        return $this === self::Dueno;
    }
}
