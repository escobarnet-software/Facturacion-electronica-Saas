<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Empresa (tenant) activa en esta petición o job.
 *
 * Mantiene sincronizadas las dos capas de aislamiento:
 *  - en PHP, para EmpresaScope;
 *  - en PostgreSQL, con set_config('app.empresa_id'), para Row Level Security.
 */
final class Tenancy
{
    private ?int $empresaId = null;

    private ?int $usuarioId = null;

    public function empresaId(): ?int
    {
        return $this->empresaId;
    }

    public function usuarioId(): ?int
    {
        return $this->usuarioId;
    }

    public function hayEmpresa(): bool
    {
        return $this->empresaId !== null;
    }

    public function establecer(int $empresaId): void
    {
        $this->empresaId = $empresaId;
        $this->fijarEnBaseDeDatos('app.empresa_id', (string) $empresaId);
    }

    public function fijarUsuario(?int $usuarioId): void
    {
        $this->usuarioId = $usuarioId;
        $this->fijarEnBaseDeDatos('app.usuario_id', $usuarioId === null ? '' : (string) $usuarioId);
    }

    /** Olvida empresa y usuario (al terminar una petición o un job). */
    public function limpiar(): void
    {
        $this->empresaId = null;
        $this->usuarioId = null;
        $this->fijarEnBaseDeDatos('app.empresa_id', '');
        $this->fijarEnBaseDeDatos('app.usuario_id', '');
        $this->fijarEnBaseDeDatos('app.sin_aislamiento', '');
    }

    /**
     * Ejecuta algo como una empresa concreta y luego restaura la anterior.
     *
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public function ejecutarComo(int $empresaId, Closure $accion): mixed
    {
        $anterior = $this->empresaId;
        $this->establecer($empresaId);

        try {
            return $accion();
        } finally {
            $anterior === null ? $this->olvidarEmpresa() : $this->establecer($anterior);
        }
    }

    /**
     * Escape explícito del aislamiento (RLS y EmpresaScope) para operaciones de
     * plataforma: crear una empresa, tareas del superadmin, el scheduler global.
     * Quien lo use debe justificarlo con un comentario en el punto de llamada.
     *
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public function sinAislamiento(Closure $accion): mixed
    {
        $this->fijarEnBaseDeDatos('app.sin_aislamiento', 'on');

        try {
            return EmpresaScope::sinScope($accion);
        } finally {
            $this->fijarEnBaseDeDatos('app.sin_aislamiento', '');
        }
    }

    private function olvidarEmpresa(): void
    {
        $this->empresaId = null;
        $this->fijarEnBaseDeDatos('app.empresa_id', '');
    }

    private function fijarEnBaseDeDatos(string $variable, string $valor): void
    {
        // set_config(..., false) dura toda la sesión de base de datos; por eso
        // limpiar() se llama siempre al final de cada petición y de cada job.
        DB::select('SELECT set_config(?, ?, false)', [$variable, $valor]);
    }
}
