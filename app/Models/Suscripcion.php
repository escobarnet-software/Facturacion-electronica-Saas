<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $plan_id
 * @property string $estado
 * @property Carbon $inicio
 * @property Carbon|null $fin
 * @property-read Plan $plan
 */
class Suscripcion extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'suscripciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fin' => 'date'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
