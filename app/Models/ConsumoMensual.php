<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $periodo
 * @property int $documentos
 * @property int|null $limite
 * @property Carbon|null $aviso_80_at
 * @property Carbon|null $aviso_100_at
 */
class ConsumoMensual extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'consumos_mensuales';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['documentos' => 'integer', 'limite' => 'integer', 'aviso_80_at' => 'datetime', 'aviso_100_at' => 'datetime'];
    }

    public function excedente(): int
    {
        return $this->limite === null ? 0 : max(0, $this->documentos - $this->limite);
    }
}
