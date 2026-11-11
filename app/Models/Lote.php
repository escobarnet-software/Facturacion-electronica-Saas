<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Lote de hasta 100 DTE enviado a Hacienda (MTIST 4.2.2).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $contingencia_id
 * @property string $id_envio
 * @property string|null $codigo_lote
 * @property string $estado
 * @property list<int> $dte_ids
 * @property array<string, mixed>|null $respuesta_mh
 */
class Lote extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'lotes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dte_ids' => 'array', 'respuesta_mh' => 'array'];
    }
}
