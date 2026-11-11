<?php

namespace App\Casts;

use App\Support\Seguridad\Secretos;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Atributo cifrado con DTE_SECRETS_KEY (ver Secretos).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class CifradoDte implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(Secretos::class)->descifrar((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, #[\SensitiveParameter] mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(Secretos::class)->cifrar((string) $value);
    }
}
