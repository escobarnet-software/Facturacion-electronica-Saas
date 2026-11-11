<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Valor de un catálogo oficial de Hacienda (CAT-001 a CAT-033).
 * Datos de referencia comunes a todas las empresas (sin empresa_id).
 *
 * @property int $id
 * @property string $catalogo
 * @property string $codigo
 * @property string $valor
 * @property string $padre
 * @property string|null $seccion
 * @property bool $vigente
 * @property string $version
 */
class CatValor extends Model
{
    protected $table = 'cat_valores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }

    /**
     * @param  Builder<CatValor>  $query
     * @return Builder<CatValor>
     */
    public function scopeDe(Builder $query, string $catalogo, ?string $padre = null): Builder
    {
        $query->where('catalogo', $catalogo)->where('vigente', true);

        return $padre === null ? $query : $query->where('padre', $padre);
    }

    /** ¿Existe y está vigente el código en el catálogo? */
    public static function existe(string $catalogo, string $codigo, ?string $padre = null): bool
    {
        return static::query()->de($catalogo, $padre)->where('codigo', $codigo)->exists();
    }
}
