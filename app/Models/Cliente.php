<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Cliente (receptor) del catálogo de la empresa.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $tipo_documento
 * @property string $num_documento
 * @property string|null $nrc
 * @property string $nombre
 * @property string|null $nombre_comercial
 * @property string|null $cod_actividad
 * @property string|null $desc_actividad
 * @property array{departamento?: string, municipio?: string, distrito?: string, complemento?: string}|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $gran_contribuyente
 */
class Cliente extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'clientes';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['direccion' => 'array', 'gran_contribuyente' => 'boolean'];
    }

    /** ¿Puede recibir Crédito Fiscal? (inscrito en IVA, con dirección y actividad). */
    public function puedeRecibirCcf(): bool
    {
        return $this->nrc !== null && $this->cod_actividad !== null && $this->desc_actividad !== null
            && isset($this->direccion['departamento'], $this->direccion['municipio'], $this->direccion['distrito'], $this->direccion['complemento']);
    }
}
