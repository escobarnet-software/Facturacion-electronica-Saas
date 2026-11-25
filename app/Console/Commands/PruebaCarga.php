<?php

namespace App\Console\Commands;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Hacienda\TransmisorDte;
use App\Enums\EstadoDte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Prueba de carga del ciclo completo (calcular → validar esquema → correlativo → guardar → firmar →
 * transmitir → aplicar sello) contra el SIMULADOR de Hacienda y del firmador (tests/Carga/simulador.php).
 *
 * Varios procesos emiten a la vez (como los workers de la cola «transmision»), cada uno para varias empresas.
 * Nunca en producción y nunca contra Hacienda: la URL del simulador debe ser local.
 */
class PruebaCarga extends Command
{
    protected $signature = 'dte:carga
        {--documentos=1500 : total de documentos}
        {--procesos=8 : procesos en paralelo}
        {--empresas=16 : empresas de prueba}
        {--simulador=http://127.0.0.1:8899 : URL del simulador local}
        {--trabajador= : (interno) ids de empresa separados por comas}
        {--cantidad=0 : (interno) documentos de este trabajador}';

    protected $description = 'Prueba de carga con Hacienda y firmador simulados (documentos por segundo)';

    public function handle(Tenancy $tenancy): int
    {
        $simulador = rtrim((string) $this->option('simulador'), '/');
        if (app()->environment('production') || ! preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $simulador)) {
            $this->error('La prueba de carga solo corre fuera de producción y contra un simulador local.');

            return self::FAILURE;
        }
        config([
            'dte.api.base_url.00' => $simulador, 'dte.firmador.url' => $simulador.'/firmardocumento/',
            'dte.entrega.automatica' => false, 'queue.default' => 'sync',
        ]);

        if ($this->option('trabajador') !== null) {
            return $this->trabajar($tenancy);
        }

        $total = (int) $this->option('documentos');
        $procesos = max(1, (int) $this->option('procesos'));
        $empresas = $this->prepararEmpresas($tenancy, (int) $this->option('empresas'));
        $this->info("Emitiendo {$total} documentos con {$procesos} procesos y ".count($empresas).' empresas…');

        $desdeId = (int) $tenancy->sinAislamiento(fn () => DB::table('dtes')->max('id'));
        $grupos = array_chunk($empresas, (int) ceil(count($empresas) / $procesos));
        $inicio = microtime(true);
        $corriendo = [];
        foreach ($grupos as $i => $grupo) {
            $cantidad = intdiv($total, count($grupos)) + ($i < $total % count($grupos) ? 1 : 0);
            $p = new Process([PHP_BINARY, base_path('artisan'), 'dte:carga', '--trabajador='.implode(',', $grupo), '--cantidad='.$cantidad, '--simulador='.$simulador]);
            $p->setTimeout(3600)->start();
            $corriendo[] = $p;
        }
        $fallas = 0;
        foreach ($corriendo as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                $fallas++;
                $this->error(trim($p->getErrorOutput() ?: $p->getOutput()));
            }
        }
        $segundos = microtime(true) - $inicio;

        $sellados = $tenancy->sinAislamiento(fn () => DB::table('dtes')->whereIn('empresa_id', $empresas)->where('id', '>', $desdeId)->where('estado', EstadoDte::Transmitido->value)->count());
        $duplicados = $tenancy->sinAislamiento(fn () => DB::table('dtes')->whereIn('empresa_id', $empresas)->select('empresa_id', 'numero_control')->groupBy('empresa_id', 'numero_control')->havingRaw('count(*) > 1')->count());
        $tasa = $sellados / max($segundos, 0.001);

        $this->table(['Documentos con sello', 'Segundos', 'Documentos por segundo', 'Números de control repetidos', 'Procesos con error'], [[$sellados, number_format($segundos, 1), number_format($tasa, 1), $duplicados, $fallas]]);

        return $fallas === 0 && $duplicados === 0 && $sellados >= $total ? self::SUCCESS : self::FAILURE;
    }

    private function trabajar(Tenancy $tenancy): int
    {
        $ids = array_map('intval', explode(',', (string) $this->option('trabajador')));
        $emision = app(EmisionDte::class);
        for ($n = 0; $n < (int) $this->option('cantidad'); $n++) {
            $id = $ids[$n % count($ids)];
            $tenancy->ejecutarComo($id, function () use ($id, $emision) {
                $empresa = Empresa::query()->findOrFail($id);
                $est = Establecimiento::query()->firstOrFail();
                $pv = PuntoVenta::query()->firstOrFail();
                $dte = $emision->emitir($empresa, $est, $pv, '01', [new LineaSolicitud('Pupusa revuelta', '6', '0.66371681'), new LineaSolicitud('Horchata', '2', '0.88495575')], transmitir: false);
                TransmisorDte::crear()->procesar($dte, $empresa, EmpresaCredencial::query()->where('ambiente', '00')->firstOrFail());
            });
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function prepararEmpresas(Tenancy $tenancy, int $cantidad): array
    {
        $ids = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $nit = '0614'.str_pad((string) (990000000 + $i), 10, '0', STR_PAD_LEFT);
            $empresa = $tenancy->sinAislamiento(fn () => Empresa::query()->firstOrCreate(['nit' => $nit], [
                'nrc' => (string) (100000 + $i), 'razon_social' => "EMPRESA DE CARGA {$i}, S.A. DE C.V.", 'nombre_comercial' => "Carga {$i}",
                'correo' => "carga{$i}@prueba.test", 'telefono' => '22223333', 'cod_actividad' => '56101', 'desc_actividad' => 'Restaurantes',
                'direccion' => ['departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'San Salvador'],
            ]));
            $tenancy->ejecutarComo($empresa->id, function () use ($empresa) {
                if (! Establecimiento::query()->exists()) {
                    $est = Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'Casa matriz', 'cod_estable_mh' => 'M001']);
                    PuntoVenta::query()->create(['establecimiento_id' => $est->id, 'nombre' => 'Caja 1', 'cod_punto_venta_mh' => 'P001']);
                }
                if (! EmpresaCredencial::query()->exists()) {
                    // Secretos FALSOS: el simulador no los valida.
                    (new EmpresaCredencial(['ambiente' => '00', 'usuario_api' => $empresa->nit]))->establecerSecretos('ClaveFalsaCarga#1', 'LlaveFalsaCarga#1')->save();
                }
            });
            $ids[] = $empresa->id;
        }

        return $ids;
    }
}
