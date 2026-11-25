<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Models\AccesoSoporte;
use App\Models\Bitacora;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Session;

/**
 * Acceso del soporte de Escobar Net a una empresa: SOLO con un permiso temporal vigente que la empresa
 * otorga, con rol de consulta (sin emitir, sin configurar y sin ver credenciales). Todo queda en la bitácora.
 */
final class Soporte
{
    public const SESION = 'soporte_empresa_id';

    public function __construct(private readonly Tenancy $tenancy) {}

    /** ¿La empresa tiene un permiso de soporte vigente? (consulta dentro de esa empresa: RLS) */
    public function vigente(int $empresaId): bool
    {
        return (bool) $this->tenancy->ejecutarComo($empresaId, fn () => AccesoSoporte::query()
            ->whereNull('revocado_at')->where('expira_at', '>', now())->exists());
    }

    /** Empresa en la que el superadmin está dando soporte en esta sesión (si el permiso sigue vigente). */
    public function empresaDeSesion(Usuario $usuario): ?int
    {
        $id = Session::get(self::SESION);
        if (! $usuario->es_superadmin || ! is_int($id)) {
            return null;
        }
        if (! $this->vigente($id)) {
            Session::forget(self::SESION);

            return null;
        }

        return $id;
    }

    public function entrar(Usuario $usuario, int $empresaId): bool
    {
        if (! $usuario->es_superadmin || ! $this->vigente($empresaId)) {
            return false;
        }
        Session::put(self::SESION, $empresaId);
        $this->tenancy->ejecutarComo($empresaId, fn () => Bitacora::registrar('soporte.acceso', null, ['superadmin' => $usuario->email]));

        return true;
    }

    public function salir(): void
    {
        Session::forget(self::SESION);
    }
}
