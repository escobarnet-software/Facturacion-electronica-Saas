<?php

namespace App\Tenancy;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para todo modelo de negocio con columna empresa_id.
 *
 * - Aplica EmpresaScope a todas las consultas.
 * - Al crear, asigna la empresa activa; impide crear para otra empresa.
 *
 * @mixin Model
 */
trait PerteneceAEmpresa
{
    public static function bootPerteneceAEmpresa(): void
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function (Model $modelo): void {
            $actual = app(Tenancy::class)->empresaId();
            $asignada = $modelo->getAttribute('empresa_id');

            if ($asignada === null) {
                if ($actual === null) {
                    throw new LogicException('No hay empresa activa: no se puede crear '.class_basename($modelo).'.');
                }
                $modelo->setAttribute('empresa_id', $actual);

                return;
            }

            if ($actual !== null && (int) $asignada !== $actual) {
                throw new LogicException('No se puede crear '.class_basename($modelo).' para otra empresa.');
            }
        });
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
