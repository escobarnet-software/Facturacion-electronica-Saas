<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evento firmado (invalidación, contingencia, retorno, operaciones especiales).
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $tipo
 * @property int $version
 * @property string $ambiente
 * @property string $codigo_generacion
 * @property int|null $dte_id
 * @property string $estado
 * @property string $json_texto
 * @property array<string, mixed> $json_original
 * @property string|null $jws
 * @property string|null $sello_recibido
 * @property Carbon|null $fh_procesamiento
 * @property array<string, mixed>|null $respuesta_mh
 */
class Evento extends Model
{
    use PerteneceAEmpresa;

    public const INVALIDACION = 'invalidacion';

    public const CONTINGENCIA = 'contingencia';

    public const RETORNO = 'retorno';

    public const OPERACIONES_ESPECIALES = 'operaciones_especiales';

    protected $table = 'eventos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'json_original' => 'array',
            'respuesta_mh' => 'array',
            'fh_procesamiento' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dte, $this>
     */
    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }
}
