<?php

namespace App\Models;

use App\Casts\CifradoDte;
use App\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Credenciales de Hacienda de una empresa para un ambiente (00 o 01).
 *
 * Los secretos pertenecen al cliente: se guardan cifrados con DTE_SECRETS_KEY,
 * están ocultos en toArray/toJson y nunca se muestran de vuelta. En memoria,
 * los atributos guardan el texto CIFRADO; solo se descifran al leerlos.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $ambiente
 * @property string $usuario_api
 * @property string $pwd_api
 * @property string $pwd_privada
 * @property string|null $certificado_sha256
 * @property Carbon|null $certificado_cargado_at
 * @property int $logins_fallidos
 * @property Carbon|null $bloqueado_at
 */
#[Hidden(['pwd_api', 'pwd_privada'])]
class EmpresaCredencial extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'empresa_credenciales';

    protected $fillable = ['ambiente', 'usuario_api'];

    protected function casts(): array
    {
        return [
            'pwd_api' => CifradoDte::class,
            'pwd_privada' => CifradoDte::class,
            'certificado_cargado_at' => 'datetime',
            'bloqueado_at' => 'datetime',
        ];
    }

    /** Única forma de cargar los secretos (desde el formulario seguro del cliente). */
    public function establecerSecretos(
        #[\SensitiveParameter] string $pwdApi,
        #[\SensitiveParameter] string $pwdPrivada,
    ): static {
        if (trim($pwdApi) === '' || trim($pwdPrivada) === '') {
            throw new InvalidArgumentException('La contraseña de la API y la de la llave privada son obligatorias.');
        }

        $this->pwd_api = $pwdApi;
        $this->pwd_privada = $pwdPrivada;
        $this->logins_fallidos = 0;
        $this->bloqueado_at = null;

        return $this;
    }

    /**
     * Lo que sí se puede mostrar en el panel: nunca los secretos.
     *
     * @return array{ambiente: string, usuario_api: string, tiene_pwd_api: bool, tiene_pwd_privada: bool, certificado_cargado_at: string|null, bloqueado: bool}
     */
    public function resumenSeguro(): array
    {
        return [
            'ambiente' => $this->ambiente,
            'usuario_api' => $this->usuario_api,
            'tiene_pwd_api' => isset($this->attributes['pwd_api']),
            'tiene_pwd_privada' => isset($this->attributes['pwd_privada']),
            'certificado_cargado_at' => $this->certificado_cargado_at?->toIso8601String(),
            'bloqueado' => $this->bloqueado_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return array_diff_key($this->attributesToArray(), array_flip(['pwd_api', 'pwd_privada']));
    }
}
