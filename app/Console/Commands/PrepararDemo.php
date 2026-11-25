<?php

namespace App\Console\Commands;

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Datos de DEMOSTRACIÓN (empresa ficticia, productos, cliente y un usuario contador). Idempotente.
 * Las credenciales de Hacienda son FALSAS: la demo usa el simulador (DTE_SIMULADOR_URL). Nunca en producción.
 */
class PrepararDemo extends Command
{
    protected $signature = 'dte:demo {--clave= : contraseña del usuario contador@demo.test (o DEMO_CLAVE)}';

    protected $description = 'Crea una empresa de demostración para probar la plataforma con Hacienda simulada';

    public function handle(Tenancy $tenancy): int
    {
        if (app()->environment('production')) {
            $this->error('La demo no se prepara en producción.');

            return self::FAILURE;
        }
        $clave = (string) ($this->option('clave') ?: config('dte.demo_clave', ''));
        if (strlen($clave) < 8) {
            $this->error('Indique una contraseña de al menos 8 caracteres con --clave= o DEMO_CLAVE.');

            return self::FAILURE;
        }

        $empresa = $tenancy->sinAislamiento(fn () => Empresa::query()->updateOrCreate(['nit' => '06141507261015'], [
            'nrc' => '3216549', 'razon_social' => 'PUPUSERÍA DEMO, S.A. DE C.V.', 'nombre_comercial' => 'Pupusería Demo',
            'correo' => 'ventas@pupuseria-demo.test', 'telefono' => '22225555', 'cod_actividad' => '56101', 'desc_actividad' => 'Restaurantes',
            'direccion' => ['departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'Colonia Escalón, San Salvador'],
            'responsable_nombre' => 'Responsable Demo', 'responsable_tipo_doc' => '13', 'responsable_num_doc' => '012345678',
            'ambiente' => '00', 'estado' => 'activa',
        ]));
        $plan = Plan::query()->firstOrCreate(['codigo' => 'demo'], ['nombre' => 'Demo', 'precio_mensual' => '25.00', 'documentos_mes' => 500, 'precio_documento_adicional' => '0.05', 'incluye_api' => true]);

        $tenancy->ejecutarComo($empresa->id, function () use ($empresa, $plan, $clave) {
            if (! Establecimiento::query()->exists()) {
                $e = Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'Casa matriz', 'cod_estable_mh' => 'M001']);
                PuntoVenta::query()->create(['establecimiento_id' => $e->id, 'nombre' => 'Caja 1', 'cod_punto_venta_mh' => 'P001']);
            }
            if (! EmpresaCredencial::query()->exists()) {
                (new EmpresaCredencial(['ambiente' => '00', 'usuario_api' => $empresa->nit]))->establecerSecretos('ClaveDemoFalsa#1', 'LlaveDemoFalsa#1')->save();
            }
            Suscripcion::query()->firstOrCreate(['plan_id' => $plan->id], ['estado' => 'activa', 'inicio' => now()->toDateString()]);
            foreach ([['Pupusa revuelta', '0.66371681'], ['Pupusa de queso', '0.61946903'], ['Horchata', '0.88495575'], ['Café', '0.70796460']] as [$descripcion, $precio]) {
                Producto::query()->firstOrCreate(['descripcion' => $descripcion], ['precio' => $precio]);
            }
            Cliente::query()->firstOrCreate(['tipo_documento' => '36', 'num_documento' => '06140101851014'], [
                'nrc' => '765432', 'nombre' => 'CLIENTE CONTRIBUYENTE DEMO, S.A. DE C.V.', 'cod_actividad' => '46900',
                'desc_actividad' => 'Venta al por mayor de otros productos', 'correo' => 'compras@cliente-demo.test',
                'direccion' => ['departamento' => '05', 'municipio' => '25', 'distrito' => '01', 'complemento' => 'Santa Tecla'],
            ]);
            $usuario = Usuario::query()->where('email', 'contador@demo.test')->first()
                ?? Usuario::query()->forceCreate(['nombre' => 'Contador Demo', 'email' => 'contador@demo.test', 'password' => $clave, 'email_verified_at' => now()]);
            $usuario->forceFill(['password' => $clave])->save();
            if (! $empresa->usuarios()->whereKey($usuario->id)->exists()) {
                $empresa->usuarios()->attach($usuario->id, ['rol' => Rol::Contador->value]);
            }
        });

        $this->info('Demo lista. Entre con contador@demo.test y la contraseña indicada.');

        return self::SUCCESS;
    }
}
