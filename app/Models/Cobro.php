<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $periodo
 * @property int $plan_id
 * @property int $documentos
 * @property int $excedente
 * @property string|null $dte_codigo_generacion
 * @property string $estado
 * @property string|null $pasarela
 * @property string|null $referencia_pago
 * @property Carbon|null $pagado_at
 */
class Cobro extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'cobros';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['documentos' => 'integer', 'excedente' => 'integer', 'pagado_at' => 'datetime'];
    }
}
