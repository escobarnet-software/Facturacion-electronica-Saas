<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->restrictOnDelete();
            $table->char('tipo', 2);
            $table->smallInteger('version');
            $table->char('ambiente', 2);
            $table->char('codigo_generacion', 36)->unique();
            $table->string('numero_control', 31);
            $table->date('fecha_emision');
            $table->time('hora_emision');
            $table->string('estado', 20);
            $table->smallInteger('modelo')->default(1);     // CAT-003: 1 previo, 2 diferido
            $table->smallInteger('operacion')->default(1);  // CAT-004: 1 normal, 2 contingencia
            $table->string('receptor_nombre', 250)->nullable();
            $table->string('receptor_documento', 25)->nullable();
            $table->decimal('total_no_suj', 18, 2)->default(0);
            $table->decimal('total_exenta', 18, 2)->default(0);
            $table->decimal('total_gravada', 18, 2)->default(0);
            $table->decimal('total_iva', 18, 2)->default(0);
            $table->decimal('total_pagar', 18, 2)->default(0);
            // El DTE tal como se firmó: texto exacto (bytes) y jsonb para consultas.
            $table->text('json_texto');
            $table->jsonb('json_original');
            $table->text('jws')->nullable();
            $table->string('sello_recibido', 40)->nullable();
            $table->timestamp('fh_procesamiento')->nullable();
            $table->jsonb('respuesta_mh')->nullable();
            $table->jsonb('observaciones_mh')->nullable();
            $table->unsignedSmallInteger('envios')->default(0);
            $table->string('archivo_json_sha256', 64)->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('relacionado_con')->nullable()->constrained('dtes')->nullOnDelete();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'numero_control']);
            $table->index(['empresa_id', 'fecha_emision']);
            $table->index(['empresa_id', 'estado']);
        });

        // Lo transmitido es inalterable: con sello, el JSON, la firma y el sello no cambian nunca.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dtes_sellado_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.sello_recibido IS NOT NULL AND (
                    NEW.json_texto IS DISTINCT FROM OLD.json_texto
                    OR NEW.json_original IS DISTINCT FROM OLD.json_original
                    OR NEW.jws IS DISTINCT FROM OLD.jws
                    OR NEW.sello_recibido IS DISTINCT FROM OLD.sello_recibido
                    OR NEW.codigo_generacion IS DISTINCT FROM OLD.codigo_generacion
                    OR NEW.numero_control IS DISTINCT FROM OLD.numero_control
                ) THEN
                    RAISE EXCEPTION 'Un DTE con sello de recepción no se puede modificar'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF OLD.sello_recibido IS NOT NULL AND TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un DTE con sello de recepción no se puede borrar'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;
            CREATE TRIGGER dtes_sellado_inmutable BEFORE UPDATE OR DELETE ON dtes
                FOR EACH ROW EXECUTE FUNCTION dtes_sellado_inmutable();
            SQL);

        Schema::create('dte_eventos_internos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('dte_id')->constrained('dtes')->restrictOnDelete();
            $table->string('de', 20)->nullable();
            $table->string('a', 20);
            $table->string('motivo', 200);
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->jsonb('respuesta')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('empresa_id');
            $table->index(['dte_id', 'id']);
        });

        Rls::activar('dtes');
        Rls::activar('dte_eventos_internos');
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_eventos_internos');
        Schema::dropIfExists('dtes');
        DB::unprepared('DROP FUNCTION IF EXISTS dtes_sellado_inmutable()');
    }
};
