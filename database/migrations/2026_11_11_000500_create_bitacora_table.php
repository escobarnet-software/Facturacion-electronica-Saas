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
        // Bitácora inmutable: quién hizo qué y cuándo. Solo se agregan filas.
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            // NULL = evento de plataforma (superadmin), sin empresa.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('accion', 100);
            $table->string('entidad_tipo', 100)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->jsonb('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['empresa_id', 'created_at']);
        });

        // Append-only: UPDATE, DELETE y TRUNCATE se rechazan en la propia base de datos.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bitacora_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La bitácora es inmutable: no se permite %', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;
            CREATE TRIGGER bitacora_sin_update_delete BEFORE UPDATE OR DELETE ON bitacora
                FOR EACH ROW EXECUTE FUNCTION bitacora_inmutable();
            CREATE TRIGGER bitacora_sin_truncate BEFORE TRUNCATE ON bitacora
                FOR EACH STATEMENT EXECUTE FUNCTION bitacora_inmutable();
            SQL);

        Rls::activar('bitacora');
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
        DB::unprepared('DROP FUNCTION IF EXISTS bitacora_inmutable()');
    }
};
