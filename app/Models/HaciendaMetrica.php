<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Métrica de una llamada a Hacienda (operación de plataforma, sin datos de negocio ni empresa).
 *
 * @property int $id
 * @property string $ambiente
 * @property string $endpoint
 * @property int|null $status
 * @property int|null $duracion_ms
 * @property string|null $codigo_msg
 */
class HaciendaMetrica extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hacienda_metricas';

    protected $guarded = ['id'];
}
