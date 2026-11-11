<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Período en que una empresa no pudo transmitir (Normativa regla 13.2).
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $ambiente
 * @property Carbon $inicio
 * @property Carbon|null $fin
 * @property int $tipo
 * @property string|null $motivo
 * @property string $estado
 * @property int|null $evento_id
 * @property Carbon|null $ultimo_intento_at
 * @property Carbon|null $alerta_informe_at
 * @property string|null $informe_borrador
 */
class Contingencia extends Model
{
    use PerteneceAEmpresa;

    public const ACTIVA = 'activa';

    public const CERRADA = 'cerrada';

    public const EVENTO_SELLADO = 'evento_sellado';

    public const COMPLETADA = 'completada';

    protected $table = 'contingencias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'ultimo_intento_at' => 'datetime',
            'alerta_informe_at' => 'datetime',
            'tipo' => 'integer',
        ];
    }

    /**
     * @return HasMany<Dte, $this>
     */
    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }
}
