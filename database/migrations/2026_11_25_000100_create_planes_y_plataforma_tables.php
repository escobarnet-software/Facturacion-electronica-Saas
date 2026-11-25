<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Planes: datos de la PLATAFORMA (como los catálogos), comunes a todas las empresas: sin empresa_id ni RLS.
        // Configurables desde el superadmin; nunca fijos en el código (ESPECIFICACION §19.4).
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->decimal('precio_mensual', 18, 2);              // sin IVA
            $table->unsignedInteger('documentos_mes')->nullable(); // NULL = ilimitado
            $table->decimal('precio_documento_adicional', 20, 8)->default(0); // sin IVA
            $table->unsignedInteger('max_establecimientos')->nullable();
            $table->unsignedInteger('max_usuarios')->nullable();
            $table->boolean('incluye_api')->default(false);
            $table->boolean('incluye_pos')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('planes');
            $table->string('estado', 20)->default('activa');      // activa | cancelada
            $table->date('inicio');
            $table->date('fin')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });

        // Consumo del mes: se cuenta al crear cada documento (nunca se bloquea la emisión por el plan).
        Schema::create('consumos_mensuales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->char('periodo', 7);                            // AAAA-MM
            $table->unsignedInteger('documentos')->default(0);
            $table->unsignedInteger('limite')->nullable();         // límite del plan al momento
            $table->timestamp('aviso_80_at')->nullable();
            $table->timestamp('aviso_100_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'periodo']);
        });

        // Cobros mensuales de Escobar Net a la empresa (plan + excedente), facturados con la propia plataforma.
        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->char('periodo', 7);
            $table->foreignId('plan_id')->constrained('planes');
            $table->decimal('cargo_plan', 18, 2);
            $table->unsignedInteger('documentos');
            $table->unsignedInteger('excedente');
            $table->decimal('cargo_excedente', 18, 2);
            $table->decimal('subtotal', 18, 2);                    // sin IVA
            $table->char('dte_codigo_generacion', 36)->nullable(); // DTE emitido por Escobar Net
            $table->string('estado', 20)->default('pendiente');    // pendiente | pagado | anulado
            $table->string('pasarela', 30)->nullable();
            $table->string('referencia_pago', 100)->nullable();
            $table->timestamp('pagado_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'periodo']);
        });

        // Permiso temporal que la EMPRESA otorga al soporte de Escobar Net (solo lectura, sin credenciales).
        Schema::create('accesos_soporte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('otorgado_por')->constrained('usuarios');
            $table->string('motivo', 250);
            $table->timestamp('expira_at');
            $table->timestamp('revocado_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });

        // Métricas de las llamadas a Hacienda (operación de la plataforma, sin datos de negocio).
        Schema::create('hacienda_metricas', function (Blueprint $table) {
            $table->id();
            $table->char('ambiente', 2);
            $table->string('endpoint', 60);
            $table->unsignedSmallInteger('status')->nullable();    // NULL = sin conexión
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->string('codigo_msg', 10)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'endpoint']);
        });

        foreach (['suscripciones', 'consumos_mensuales', 'cobros', 'accesos_soporte'] as $tabla) {
            Rls::activar($tabla);
        }
    }

    public function down(): void
    {
        foreach (['hacienda_metricas', 'accesos_soporte', 'cobros', 'consumos_mensuales', 'suscripciones', 'planes'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
