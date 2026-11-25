<?php

namespace App\Models;

use App\Casts\CifradoDte;
use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Suscripción de la empresa a eventos (webhook firmado con HMAC-SHA256).
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $url
 * @property string $secreto
 * @property list<string> $eventos
 * @property bool $activo
 */
class Webhook extends Model
{
    use PerteneceAEmpresa;

    public const EVENTOS = [
        'dte.transmitido' => 'Documento con sello de Hacienda',
        'dte.rechazado' => 'Documento rechazado por Hacienda',
        'dte.invalidado' => 'Documento invalidado',
        'contingencia.iniciada' => 'Inicio de contingencia',
        'contingencia.finalizada' => 'Fin de contingencia',
    ];

    protected $guarded = ['id', 'empresa_id'];

    protected $hidden = ['secreto'];

    protected function casts(): array
    {
        return ['secreto' => CifradoDte::class, 'eventos' => 'array', 'activo' => 'boolean'];
    }
}
