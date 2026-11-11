<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Avance de las pruebas mínimas de un tipo de DTE o evento.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $tipo
 * @property int $requeridas
 * @property int $exitosas
 * @property int $fallidas
 * @property string $estado
 * @property string|null $ultimo_error
 */
class PruebaMinima extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'pruebas_minimas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requeridas' => 'integer', 'exitosas' => 'integer', 'fallidas' => 'integer'];
    }

    public function porcentaje(): int
    {
        return $this->requeridas === 0 ? 100 : (int) floor(min($this->exitosas, $this->requeridas) * 100 / $this->requeridas);
    }
}
