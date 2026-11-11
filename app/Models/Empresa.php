<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa cliente (tenant). Ante Hacienda, es la emisora.
 *
 * No usa EmpresaScope (la tabla ES la empresa); su aislamiento lo da RLS:
 * solo se ve la empresa activa y las empresas del usuario autenticado.
 *
 * @property int $id
 * @property string $nit
 * @property string|null $nrc
 * @property string $razon_social
 * @property string|null $nombre_comercial
 * @property string $ambiente
 * @property string $modo_correlativo
 * @property string $estado
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'agente_retencion' => 'boolean',
            'agente_percepcion' => 'boolean',
            'direccion' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<Usuario, $this, EmpresaUsuario, 'membresia'>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'empresa_usuario')
            ->using(EmpresaUsuario::class)
            ->as('membresia')
            ->withPivot(['rol', 'punto_venta_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Establecimiento, $this>
     */
    public function establecimientos(): HasMany
    {
        return $this->hasMany(Establecimiento::class);
    }

    /**
     * @return HasMany<EmpresaCredencial, $this>
     */
    public function credenciales(): HasMany
    {
        return $this->hasMany(EmpresaCredencial::class);
    }
}
