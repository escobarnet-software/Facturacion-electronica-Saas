<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Rastro de cada transición de estado de un DTE.
 *
 * @property int $id
 * @property int $dte_id
 * @property string|null $de
 * @property string $a
 * @property string $motivo
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $respuesta
 */
class DteEventoInterno extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'dte_eventos_internos';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'respuesta' => 'array', 'created_at' => 'datetime'];
    }
}
