<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Dte\Excepciones\DteException;
use App\Dte\Firma\FirmadorClient;
use App\Dte\Validacion\ValidadorEsquema;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Evento;

/** Valida contra el esquema oficial, firma y guarda un evento. */
final class FirmaEventos
{
    public function __construct(
        private readonly FirmadorClient $firmador = new FirmadorClient,
        private readonly ?ValidadorEsquema $validador = null,
    ) {}

    public function firmarYGuardar(Empresa $empresa, EmpresaCredencial $credencial, string $tipo, int $version, string $json, ?int $dteId = null): Evento
    {
        $validador = $this->validador ?? new ValidadorEsquema((string) config('dte.esquemas'));
        $errores = $validador->validar($json, $tipo, $version);
        if ($errores !== []) {
            throw new DteException('El evento no cumple el esquema oficial de Hacienda: '.implode('; ', array_map(
                fn ($ruta, $mensajes) => $ruta.': '.implode(', ', $mensajes), array_keys($errores), $errores,
            )));
        }

        $jws = $this->firmador->firmar($empresa->nit, $credencial->pwd_privada, $json);
        $documento = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return Evento::query()->create([
            'tipo' => $tipo,
            'version' => $version,
            'ambiente' => $credencial->ambiente,
            'codigo_generacion' => $documento['identificacion']['codigoGeneracion'],
            'dte_id' => $dteId,
            'estado' => 'firmado',
            'json_texto' => $json,
            'json_original' => $documento,
            'jws' => $jws,
        ]);
    }
}
