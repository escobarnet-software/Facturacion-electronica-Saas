<?php

namespace App\Models;

use App\Enums\EstadoDte;
use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Documento Tributario Electrónico de una empresa.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $establecimiento_id
 * @property int $punto_venta_id
 * @property string $tipo
 * @property int $version
 * @property string $ambiente
 * @property string $codigo_generacion
 * @property string $numero_control
 * @property Carbon $fecha_emision
 * @property string $hora_emision
 * @property EstadoDte $estado
 * @property int $modelo
 * @property int $operacion
 * @property string $json_texto
 * @property array<string, mixed> $json_original
 * @property string|null $jws
 * @property string|null $sello_recibido
 * @property Carbon|null $fh_procesamiento
 * @property array<string, mixed>|null $respuesta_mh
 * @property list<string>|null $observaciones_mh
 * @property int $envios
 */
class Dte extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'dtes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoDte::class,
            'fecha_emision' => 'date',
            'fh_procesamiento' => 'datetime',
            'json_original' => 'array',
            'respuesta_mh' => 'array',
            'observaciones_mh' => 'array',
            'version' => 'integer',
            'modelo' => 'integer',
            'operacion' => 'integer',
            'envios' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Establecimiento, $this>
     */
    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    /**
     * @return HasMany<DteEventoInterno, $this>
     */
    public function eventosInternos(): HasMany
    {
        return $this->hasMany(DteEventoInterno::class)->orderBy('id');
    }

    /**
     * Cambia de estado y deja el rastro en dte_eventos_internos (quién, cuándo, payload, respuesta).
     *
     * @param  array<string, mixed>  $cambios
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $respuesta
     */
    public function transicionar(EstadoDte $a, string $motivo, array $cambios = [], ?array $payload = null, ?array $respuesta = null): void
    {
        $de = $this->estado;
        $this->forceFill([...$cambios, 'estado' => $a])->save();

        $this->eventosInternos()->create([
            'empresa_id' => $this->empresa_id,
            'de' => $de->value,
            'a' => $a->value,
            'motivo' => $motivo,
            'usuario_id' => Auth::id(),
            'payload' => $payload,
            'respuesta' => $respuesta,
        ]);
    }
}
