<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta por la empresa activa. Sin empresa activa no devuelve
 * nada (falla cerrado). Primera capa de aislamiento; la segunda es RLS.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class EmpresaScope implements Scope
{
    private static bool $desactivado = false;

    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (self::$desactivado) {
            return;
        }

        $empresaId = app(Tenancy::class)->empresaId();
        $columna = $model->qualifyColumn(method_exists($model, 'columnaEmpresa') ? $model->columnaEmpresa() : 'empresa_id');

        if ($empresaId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($columna, $empresaId);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public static function sinScope(Closure $accion): mixed
    {
        $antes = self::$desactivado;
        self::$desactivado = true;

        try {
            return $accion();
        } finally {
            self::$desactivado = $antes;
        }
    }
}
