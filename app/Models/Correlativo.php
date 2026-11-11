<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Último correlativo usado por ámbito (clave) y año.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $clave
 * @property int $anio
 * @property int $ultimo
 */
class Correlativo extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'correlativos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'ultimo' => 'integer'];
    }
}
