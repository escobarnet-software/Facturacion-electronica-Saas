<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $otorgado_por
 * @property string $motivo
 * @property Carbon $expira_at
 * @property Carbon|null $revocado_at
 */
class AccesoSoporte extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'accesos_soporte';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['expira_at' => 'datetime', 'revocado_at' => 'datetime'];
    }

    public function vigente(): bool
    {
        return $this->revocado_at === null && $this->expira_at->isFuture();
    }
}
