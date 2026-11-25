<?php

namespace App\Models;

use App\Tenancy\Tenancy;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token de la API de una empresa (Sanctum, con permisos por token).
 *
 * Al encontrar el token se fija su empresa (EmpresaScope + RLS) ANTES de cargar la empresa dueña:
 * la tabla `empresas` tiene RLS y sin empresa activa no se vería. El middleware `api.empresa`
 * la vuelve a fijar y la limpia al terminar la petición.
 */
class TokenApi extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** Permisos que puede tener un token. */
    public const PERMISOS = [
        'dte:emitir' => 'Emitir documentos',
        'dte:leer' => 'Consultar documentos (estado, PDF y JSON)',
        'dte:invalidar' => 'Invalidar documentos',
        'catalogos:leer' => 'Consultar catálogos',
    ];

    public static function findToken($token)
    {
        $encontrado = parent::findToken($token);
        if ($encontrado instanceof self && $encontrado->tokenable_type === Empresa::class) {
            app(Tenancy::class)->establecer((int) $encontrado->tokenable_id);
        }

        return $encontrado;
    }
}
