<?php

namespace App\Models;

use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $webhook_id
 * @property string $uuid
 * @property string $evento
 * @property string $cuerpo
 * @property string $estado
 * @property int $intentos
 * @property int|null $ultimo_status
 * @property string|null $ultimo_error
 * @property Carbon|null $entregado_at
 * @property-read Webhook $webhook
 */
class WebhookEntrega extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'webhook_entregas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['entregado_at' => 'datetime'];
    }

    /** @return BelongsTo<Webhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
