<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 14)->unique();
            $table->string('nrc', 8)->nullable();
            $table->string('razon_social', 250);
            $table->string('nombre_comercial', 150)->nullable();
            // Ambiente de Hacienda en el que emite: 00 pruebas, 01 producción (CAT-001).
            $table->char('ambiente', 2)->default('00');
            // por_tipo | por_establecimiento | centralizado (decisión pendiente en PLAN.md).
            $table->string('modo_correlativo', 30)->default('por_tipo');
            // grande | mediano | otro
            $table->string('tipo_contribuyente', 20)->default('otro');
            $table->boolean('agente_retencion')->default(false);
            $table->boolean('agente_percepcion')->default(false);
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->jsonb('direccion')->nullable();
            // activa | suspendida | credenciales_invalidas
            $table->string('estado', 30)->default('activa');
            $table->timestamps();
        });

        // RLS de esta tabla se activa en la migración de empresa_usuario (la necesita).
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
