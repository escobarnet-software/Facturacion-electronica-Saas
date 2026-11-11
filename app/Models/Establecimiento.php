<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Database\Factories\EstablecimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Casa matriz, sucursal, bodega o patio, con el código que asigna Hacienda.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $tipo
 * @property string $nombre
 * @property string $cod_estable_mh
 * @property string|null $cod_estable
 */
class Establecimiento extends Model
{
    /** @use HasFactory<EstablecimientoFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'establecimientos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['direccion' => 'array', 'activo' => 'boolean'];
    }

    /**
     * @return HasMany<PuntoVenta, $this>
     */
    public function puntosVenta(): HasMany
    {
        return $this->hasMany(PuntoVenta::class);
    }
}
