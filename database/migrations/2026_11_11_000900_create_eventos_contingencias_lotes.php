<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eventos firmados que afectan DTE: invalidación, contingencia, retorno y operaciones especiales.
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('tipo', 30);                 // invalidacion | contingencia | retorno | operaciones_especiales
            $table->smallInteger('version');
            $table->char('ambiente', 2);
            $table->char('codigo_generacion', 36)->unique();
            $table->foreignId('dte_id')->nullable()->constrained('dtes')->restrictOnDelete(); // DTE afectado (invalidación/retorno)
            $table->string('estado', 20);               // borrador | firmado | enviado | procesado | rechazado
            $table->text('json_texto');
            $table->jsonb('json_original');
            $table->text('jws')->nullable();
            $table->string('sello_recibido', 40)->nullable();
            $table->timestamp('fh_procesamiento')->nullable();
            $table->jsonb('respuesta_mh')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'tipo', 'estado']);
        });

        Schema::create('contingencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->char('ambiente', 2);
            $table->timestamp('inicio');
            $table->timestamp('fin')->nullable();       // cese de la falla
            $table->smallInteger('tipo');               // CAT-005
            $table->string('motivo', 500)->nullable();
            // activa | cerrada (servicio de vuelta) | evento_sellado | completada
            $table->string('estado', 20)->default('activa');
            $table->foreignId('evento_id')->nullable()->constrained('eventos')->nullOnDelete();
            $table->timestamp('ultimo_intento_at')->nullable();
            $table->timestamp('alerta_informe_at')->nullable();
            $table->text('informe_borrador')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('contingencia_id')->nullable()->constrained('contingencias')->nullOnDelete();
            $table->char('id_envio', 36)->unique();
            $table->string('codigo_lote', 36)->nullable();
            $table->string('estado', 20);               // enviado | recibido | procesado | rechazado
            $table->jsonb('dte_ids');
            $table->jsonb('respuesta_mh')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'estado']);
        });

        Schema::table('dtes', function (Blueprint $table) {
            $table->foreignId('contingencia_id')->nullable()->after('relacionado_con')->constrained('contingencias')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->after('contingencia_id')->constrained('lotes')->nullOnDelete();
            // Fecha y hora del sello: base de los plazos de invalidación y retorno.
            $table->boolean('medicamento_perecedero')->default(false)->after('lote_id');
        });

        Rls::activar('eventos');
        Rls::activar('contingencias');
        Rls::activar('lotes');
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_id');
            $table->dropConstrainedForeignId('contingencia_id');
            $table->dropColumn('medicamento_perecedero');
        });
        Schema::dropIfExists('lotes');
        Schema::dropIfExists('contingencias');
        Schema::dropIfExists('eventos');
    }
};
