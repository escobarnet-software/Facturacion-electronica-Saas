<?php

declare(strict_types=1);

namespace App\Dte\Hacienda;

use App\Models\Bitacora;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del Sistema de Transmisión de Hacienda (MTIST v2, sección 4) para UNA empresa.
 *
 * - URL, rutas, timeout y horas del token salen de config/dte.php.
 * - El token se guarda en caché por empresa y ambiente, y se renueva antes de vencer.
 * - Nunca reintenta el login en bucle: al llegar a max_logins_fallidos se bloquea y avisa.
 */
final class HaciendaClient
{
    public function __construct(
        private readonly Empresa $empresa,
        private readonly EmpresaCredencial $credencial,
    ) {}

    public function ambiente(): string
    {
        return $this->credencial->ambiente;
    }

    /** Token vigente (de la caché o de un login nuevo). */
    public function token(): string
    {
        $token = Cache::get($this->llaveToken());

        return is_string($token) && $token !== '' ? $token : $this->autenticar();
    }

    public function olvidarToken(): void
    {
        Cache::forget($this->llaveToken());
    }

    public function autenticar(): string
    {
        $limite = (int) config('dte.api.max_logins_fallidos');
        if ($this->credencial->bloqueado_at !== null || $this->credencial->logins_fallidos >= $limite) {
            throw new CredencialesBloqueadasException(
                'Las credenciales de la API de Hacienda fallaron varias veces y se dejaron de usar para no bloquear su usuario. '
                .'Verifíquelas en el portal de Hacienda y cárguelas de nuevo en Credenciales DTE.'
            );
        }

        try {
            $respuesta = $this->peticion()->asForm()->post($this->url('autenticacion'), [
                'user' => $this->credencial->usuario_api,
                'pwd' => $this->credencial->pwd_api,
            ]);
        } catch (ConnectionException) {
            // Un problema de red NO cuenta como intento fallido de credenciales.
            throw new SinRespuestaException('Hacienda no respondió al iniciar sesión.');
        }

        $datos = $respuesta->json();
        $token = is_array($datos) && ($datos['status'] ?? null) === 'OK' ? ($datos['body']['token'] ?? null) : null;

        if (is_string($token) && $token !== '') {
            $this->credencial->forceFill(['logins_fallidos' => 0])->save();
            // El token ya trae el prefijo «Bearer» (MTIST 4.1).
            Cache::put($this->llaveToken(), $token, now()->addHours((int) config('dte.api.token_horas.'.$this->ambiente(), 23)));

            return $token;
        }

        if ($respuesta->serverError()) {
            throw new SinRespuestaException('El servicio de autenticación de Hacienda no está disponible.');
        }

        return $this->registrarLoginFallido($limite, is_array($datos) ? (string) ($datos['message'] ?? '') : '');
    }

    /**
     * Recepción uno a uno (MTIST 4.2.1). Devuelve la respuesta completa de Hacienda.
     *
     * @return array<string, mixed>
     *
     * @throws SinRespuestaException si no hay respuesta en 8 s o hay error de red/servidor
     */
    public function recibirDte(string $jws, string $tipoDte, int $version, string $codigoGeneracion, int $idEnvio): array
    {
        return $this->enviarJson('recepcion', [
            'ambiente' => $this->ambiente(),
            'idEnvio' => $idEnvio,
            'version' => $version,
            'tipoDte' => $tipoDte,
            'documento' => $jws,
            'codigoGeneracion' => $codigoGeneracion,
        ]);
    }

    /**
     * Consulta de un DTE (MTIST 4.3.1). null si Hacienda no lo tiene.
     *
     * @return array<string, mixed>|null
     */
    public function consultarDte(string $tipoDte, string $codigoGeneracion): ?array
    {
        $respuesta = $this->enviarJson('consulta', [
            'nitEmisor' => $this->empresa->nit,
            'tdte' => $tipoDte,
            'codigoGeneracion' => $codigoGeneracion,
        ]);

        return ($respuesta['selloRecibido'] ?? null) !== null || ($respuesta['estado'] ?? null) === 'PROCESADO' ? $respuesta : null;
    }

    /**
     * Recepción por lote (MTIST 4.2.2): hasta 100 DTE.
     *
     * @param  list<string>  $documentos
     * @return array<string, mixed>
     */
    public function recibirLote(array $documentos, string $idEnvio, int $version = 1): array
    {
        if (count($documentos) > (int) config('dte.lote.max_documentos')) {
            throw new HaciendaException('Un lote admite como máximo '.config('dte.lote.max_documentos').' documentos.');
        }

        return $this->enviarJson('recepcion_lote', [
            'ambiente' => $this->ambiente(),
            'idEnvio' => $idEnvio,
            'version' => $version,
            'nitEmisor' => $this->empresa->nit,
            'documentos' => $documentos,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarLote(string $codigoLote): array
    {
        return $this->conToken(fn (string $token) => $this->peticion($token)
            ->get($this->url('consulta_lote', ['codigoLote' => $codigoLote])));
    }

    /**
     * @return array<string, mixed>
     */
    public function enviarContingencia(string $jwsEvento): array
    {
        return $this->enviarJson('contingencia', ['nit' => $this->empresa->nit, 'documento' => $jwsEvento]);
    }

    /**
     * @return array<string, mixed>
     */
    public function enviarInvalidacion(string $jwsEvento, int $version, int $idEnvio): array
    {
        return $this->enviarJson('invalidacion', [
            'ambiente' => $this->ambiente(), 'idEnvio' => $idEnvio, 'version' => $version, 'documento' => $jwsEvento,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function enviarJson(string $ruta, array $cuerpo): array
    {
        return $this->conToken(fn (string $token) => $this->peticion($token)->asJson()->post($this->url($ruta), $cuerpo));
    }

    /**
     * Ejecuta una petición con token; si Hacienda dice que el token venció (401/403), lo renueva UNA vez.
     *
     * @param  callable(string): Response  $enviar
     * @return array<string, mixed>
     */
    private function conToken(callable $enviar): array
    {
        try {
            $respuesta = $enviar($this->token());
            if (in_array($respuesta->status(), [401, 403], true)) {
                $this->olvidarToken();
                $respuesta = $enviar($this->autenticar());
            }
        } catch (ConnectionException) {
            throw new SinRespuestaException('Hacienda no respondió en '.config('dte.api.timeout_segundos').' segundos.');
        }

        if ($respuesta->serverError()) {
            throw new SinRespuestaException('El servicio de Hacienda no está disponible (error '.$respuesta->status().').');
        }

        $datos = $respuesta->json();
        if (! is_array($datos)) {
            throw new SinRespuestaException('Hacienda devolvió una respuesta que no se pudo leer.');
        }

        return $datos;
    }

    private function registrarLoginFallido(int $limite, string $mensajeMh): never
    {
        $fallidos = $this->credencial->logins_fallidos + 1;
        $cambios = ['logins_fallidos' => $fallidos];

        if ($fallidos >= $limite) {
            $cambios['bloqueado_at'] = now();
            $this->credencial->forceFill($cambios)->save();
            $this->empresa->forceFill(['estado' => 'credenciales_invalidas'])->save();
            Bitacora::registrar('hacienda.credenciales_bloqueadas', $this->credencial, ['ambiente' => $this->ambiente(), 'intentos' => $fallidos]);

            throw new CredencialesBloqueadasException(
                "Hacienda rechazó las credenciales de la API {$fallidos} veces. Se dejaron de usar para no bloquear su usuario. "
                .'Verifíquelas en el portal de Hacienda y cárguelas de nuevo en Credenciales DTE.'
            );
        }

        $this->credencial->forceFill($cambios)->save();
        Bitacora::registrar('hacienda.login_fallido', $this->credencial, ['ambiente' => $this->ambiente(), 'intentos' => $fallidos]);

        throw new LoginFallidoException('Hacienda rechazó las credenciales de la API'.($mensajeMh !== '' ? " ({$mensajeMh})." : '.'));
    }

    private function peticion(?string $token = null): PendingRequest
    {
        $peticion = Http::timeout((int) config('dte.api.timeout_segundos'))
            ->withUserAgent((string) config('dte.api.user_agent'))
            ->acceptJson();

        return $token === null ? $peticion : $peticion->withHeaders(['Authorization' => $token]);
    }

    /** @param  array<string, string>  $parametros */
    private function url(string $ruta, array $parametros = []): string
    {
        $base = (string) config('dte.api.base_url.'.$this->ambiente());
        $camino = (string) config('dte.api.rutas.'.$ruta);
        foreach ($parametros as $clave => $valor) {
            $camino = str_replace('{'.$clave.'}', rawurlencode($valor), $camino);
        }

        return $base.$camino;
    }

    private function llaveToken(): string
    {
        return "dte:token:{$this->empresa->id}:{$this->ambiente()}";
    }
}
