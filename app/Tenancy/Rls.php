<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Row Level Security de PostgreSQL para las tablas de negocio.
 *
 * Segunda capa de aislamiento (la primera es EmpresaScope). Aunque el código
 * olvide filtrar por empresa, PostgreSQL solo devuelve y solo deja escribir
 * filas cuyo empresa_id coincide con la variable de sesión app.empresa_id.
 *
 * FORCE hace que la política aplique también al dueño de la tabla.
 * Sin app.empresa_id fijado, no se ve ni se escribe nada (falla cerrado).
 */
final class Rls
{
    /** Empresa activa de la sesión de base de datos (o NULL). */
    public const EMPRESA_ACTUAL = "NULLIF(current_setting('app.empresa_id', true), '')::bigint";

    /** Usuario autenticado de la sesión de base de datos (o NULL). */
    public const USUARIO_ACTUAL = "NULLIF(current_setting('app.usuario_id', true), '')::bigint";

    /** Escape explícito y auditado (ver Tenancy::sinAislamiento). */
    public const SIN_AISLAMIENTO = "current_setting('app.sin_aislamiento', true) = 'on'";

    /**
     * Activa RLS en una tabla con columna empresa_id.
     *
     * @param  string|null  $lecturaExtra  condición adicional SOLO para leer (USING).
     */
    public static function activar(string $tabla, string $columna = 'empresa_id', ?string $lecturaExtra = null): void
    {
        $propia = "{$columna} = ".self::EMPRESA_ACTUAL;
        $lectura = "({$propia}) OR (".self::SIN_AISLAMIENTO.')'.($lecturaExtra ? " OR ({$lecturaExtra})" : '');
        $escritura = "({$propia}) OR (".self::SIN_AISLAMIENTO.')';

        DB::statement("ALTER TABLE {$tabla} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$tabla} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY {$tabla}_leer ON {$tabla} FOR SELECT USING ({$lectura})");
        DB::statement("CREATE POLICY {$tabla}_insertar ON {$tabla} FOR INSERT WITH CHECK ({$escritura})");
        DB::statement("CREATE POLICY {$tabla}_actualizar ON {$tabla} FOR UPDATE USING ({$escritura}) WITH CHECK ({$escritura})");
        DB::statement("CREATE POLICY {$tabla}_borrar ON {$tabla} FOR DELETE USING ({$escritura})");
    }
}
