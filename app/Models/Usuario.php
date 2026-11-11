<?php

namespace App\Models;

use App\Enums\Rol;
use App\Tenancy\Tenancy;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Usuario global de la plataforma. Su rol depende de la empresa (empresa_usuario).
 *
 * @property int $id
 * @property string $nombre
 * @property string $email
 * @property bool $es_superadmin
 * @property int|null $empresa_activa_id
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable(['nombre', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $table = 'usuarios';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_superadmin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Empresas a las que pertenece, con su rol.
     *
     * @return BelongsToMany<Empresa, $this, EmpresaUsuario, 'membresia'>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'empresa_usuario')
            ->using(EmpresaUsuario::class)
            ->as('membresia')
            ->withPivot(['rol', 'punto_venta_id'])
            ->withTimestamps();
    }

    /** Rol en la empresa activa (o null). */
    public function rolActual(): ?Rol
    {
        $empresaId = app(Tenancy::class)->empresaId();

        return $empresaId === null ? null : $this->rolEn($empresaId);
    }

    /** Punto de venta asignado al cajero en la empresa activa. */
    public function puntoVentaActual(): ?int
    {
        $empresaId = app(Tenancy::class)->empresaId();
        $id = $empresaId === null ? null : $this->empresas()->whereKey($empresaId)->value('empresa_usuario.punto_venta_id');

        return $id === null ? null : (int) $id;
    }

    public function perteneceA(int $empresaId): bool
    {
        return $this->empresas()->whereKey($empresaId)->exists();
    }

    public function rolEn(int $empresaId): ?Rol
    {
        $rol = $this->empresas()->whereKey($empresaId)->value('empresa_usuario.rol');

        return is_string($rol) ? Rol::from($rol) : null;
    }

    /** Dueños de alguna empresa y superadmin deben tener 2FA confirmado. */
    public function exige2fa(): bool
    {
        return $this->es_superadmin
            || $this->empresas()->wherePivot('rol', Rol::Dueno->value)->exists();
    }

    public function tiene2fa(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
