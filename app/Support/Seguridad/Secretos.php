<?php

namespace App\Support\Seguridad;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Cifra los secretos de los clientes (contraseña de la API de Hacienda y de la
 * llave privada) con una llave dedicada, DTE_SECRETS_KEY, separada de APP_KEY.
 * Así, filtrar APP_KEY no basta para leer las credenciales de los clientes.
 *
 * Rotación: la llave nueva va en DTE_SECRETS_KEY y las anteriores en DTE_SECRETS_PREVIOUS_KEYS
 * (separadas por comas). Se descifra con cualquiera y se cifra siempre con la nueva; después,
 * `dte:secretos:rotar` vuelve a cifrar todo con la nueva y las anteriores se pueden retirar.
 */
final class Secretos
{
    private ?Encrypter $encrypter = null;

    public function cifrar(#[\SensitiveParameter] string $valor): string
    {
        return $this->encrypter()->encryptString($valor);
    }

    public function descifrar(string $cifrado): string
    {
        return $this->encrypter()->decryptString($cifrado);
    }

    /** ¿Este valor ya está cifrado con la llave ACTUAL (y no con una anterior)? */
    public function usaLlaveActual(string $cifrado): bool
    {
        try {
            (new Encrypter($this->llave((string) config('dte.secrets_key')), 'aes-256-gcm'))->decryptString($cifrado);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /** Olvida el cifrador en memoria (tras cambiar la configuración de llaves). */
    public function reiniciar(): void
    {
        $this->encrypter = null;
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter !== null) {
            return $this->encrypter;
        }

        $configurada = (string) config('dte.secrets_key');
        if ($configurada === '') {
            throw new RuntimeException('Falta DTE_SECRETS_KEY en el .env (llave para cifrar credenciales de clientes).');
        }
        $llave = $this->llave($configurada);
        $anteriores = array_map(fn (string $k) => $this->llave(trim($k)), array_values(array_filter(
            explode(',', (string) config('dte.secrets_previous_keys')), fn (string $k) => trim($k) !== '',
        )));
        foreach ([$llave, ...$anteriores] as $k) {
            if ($k === $this->llave((string) config('app.key'))) {
                throw new RuntimeException('DTE_SECRETS_KEY debe ser distinta de APP_KEY (también sus llaves anteriores).');
            }
        }

        return $this->encrypter = (new Encrypter($llave, 'aes-256-gcm'))->previousKeys($anteriores);
    }

    private function llave(string $valor): string
    {
        return str_starts_with($valor, 'base64:') ? (string) base64_decode(substr($valor, 7), true) : $valor;
    }
}
