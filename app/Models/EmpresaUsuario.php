<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membresía de un usuario en una empresa, con su rol.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $usuario_id
 * @property string $rol
 * @property int|null $punto_venta_id
 */
class EmpresaUsuario extends Pivot
{
    use PerteneceAEmpresa;

    protected $table = 'empresa_usuario';

    public $incrementing = true;

    protected $guarded = ['id'];
}
