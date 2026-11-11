<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DTE recibido de un proveedor.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $tipo
 * @property string $codigo_generacion
 * @property string $numero_control
 * @property Carbon $fecha_emision
 * @property string $emisor_nit
 * @property string|null $emisor_nrc
 * @property string $emisor_nombre
 * @property string $sello_recibido
 * @property string $json_texto
 * @property array<string, mixed> $json_original
 * @property bool $firma_coincide
 * @property bool|null $verificado_publico
 * @property string $origen
 */
class DteRecibido extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'dte_recibidos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_emision' => 'date', 'json_original' => 'array', 'firma_coincide' => 'boolean', 'verificado_publico' => 'boolean'];
    }
}
