<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Producto o servicio del catálogo de la empresa. Precio SIN IVA (NUMERIC 20,8, como texto).
 *
 * @property int $id
 * @property int $empresa_id
 * @property string|null $codigo
 * @property string|null $codigo_barras
 * @property string $descripcion
 * @property string $precio
 * @property string $tipo_venta
 * @property int $tipo_item
 * @property int $uni_medida
 * @property bool $activo
 */
class Producto extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'productos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        // El precio se maneja como texto: nunca float.
        return ['precio' => 'string', 'tipo_item' => 'integer', 'uni_medida' => 'integer', 'activo' => 'boolean'];
    }
}
