<?php

namespace App\Models;

use App\Tenancy\EmpresaScope;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Bitácora inmutable (append-only). La base de datos rechaza UPDATE, DELETE y
 * TRUNCATE con un trigger; este modelo además se niega a actualizar o borrar.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $usuario_id
 * @property string $accion
 * @property string|null $entidad_tipo
 * @property int|null $entidad_id
 * @property array<string, mixed>|null $datos
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmpresaScope);

        static::updating(fn () => throw new LogicException('La bitácora es inmutable.'));
        static::deleting(fn () => throw new LogicException('La bitácora es inmutable.'));
    }

    /**
     * Registra un evento para la empresa activa.
     *
     * @param  array<string, mixed>  $datos  Nunca incluir secretos aquí.
     */
    public static function registrar(string $accion, ?Model $entidad = null, array $datos = []): self
    {
        $empresaId = app(Tenancy::class)->empresaId();
        $peticion = app()->runningInConsole() ? null : request();

        return static::query()->create([
            'empresa_id' => $empresaId,
            'usuario_id' => Auth::id(),
            'accion' => $accion,
            'entidad_tipo' => $entidad ? $entidad::class : null,
            'entidad_id' => $entidad?->getKey(),
            'datos' => $datos === [] ? null : $datos,
            'ip' => $peticion?->ip(),
            'user_agent' => $peticion?->userAgent(),
        ]);
    }
}
