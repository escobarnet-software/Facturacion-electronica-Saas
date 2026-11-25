<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan de suscripción (dato de plataforma, configurable por el superadmin).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int|null $documentos_mes
 * @property int|null $max_establecimientos
 * @property int|null $max_usuarios
 * @property bool $incluye_api
 * @property bool $incluye_pos
 * @property bool $activo
 */
class Plan extends Model
{
    protected $table = 'planes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['incluye_api' => 'boolean', 'incluye_pos' => 'boolean', 'activo' => 'boolean'];
    }
}
