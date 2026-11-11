<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de venta (caja) de un establecimiento.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $establecimiento_id
 * @property string $nombre
 * @property string $cod_punto_venta_mh
 * @property string|null $cod_punto_venta
 */
class PuntoVenta extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'puntos_venta';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * @return BelongsTo<Establecimiento, $this>
     */
    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }
}
