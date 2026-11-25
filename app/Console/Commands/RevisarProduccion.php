<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lista de verificación automática de la configuración de producción (parte de la checklist de seguridad).
 * Falla si algo crítico está mal; no imprime valores de llaves ni secretos.
 */
class RevisarProduccion extends Command
{
    protected $signature = 'plataforma:revisar-produccion';

    protected $description = 'Verifica que la configuración sea segura para producción';

    public function handle(): int
    {
        $appKey = (string) config('app.key');
        $dteKey = (string) config('dte.secrets_key');
        $usuarioBd = (object) (DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user') ?? ['rolsuper' => true, 'rolbypassrls' => true]);

        $revisiones = [
            'APP_ENV es production' => config('app.env') === 'production',
            'APP_DEBUG está apagado' => config('app.debug') === false,
            'APP_URL usa https' => str_starts_with((string) config('app.url'), 'https://'),
            'APP_KEY configurada' => $appKey !== '',
            'DTE_SECRETS_KEY configurada y distinta de APP_KEY' => $dteKey !== '' && $dteKey !== $appKey,
            'Cookie de sesión solo por HTTPS (SESSION_SECURE_COOKIE)' => config('session.secure') === true,
            'Sesión cifrada (SESSION_ENCRYPT)' => config('session.encrypt') === true,
            'Cookie de sesión HttpOnly' => config('session.http_only') === true,
            'La BD usa un rol sin superusuario ni BYPASSRLS' => ! $usuarioBd->rolsuper && ! $usuarioBd->rolbypassrls,
            'Colas en Redis (no sync)' => config('queue.default') === 'redis',
            'Caché en Redis' => config('cache.default') === 'redis',
            'Archivo de DTE en S3' => config('dte.archivo.disco') === 's3',
            'Firmador en la red interna (http://firmador o host privado)' => ! str_contains((string) config('dte.firmador.url'), 'localhost'),
            'Log sin nivel debug' => config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug',
        ];

        $fallas = 0;
        foreach ($revisiones as $texto => $ok) {
            $this->line(($ok ? '  [ok]    ' : '  [FALLA] ').$texto);
            $fallas += $ok ? 0 : 1;
        }
        $this->newLine();
        $fallas === 0 ? $this->info('Configuración lista para producción.') : $this->error("{$fallas} punto(s) por corregir.");

        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
