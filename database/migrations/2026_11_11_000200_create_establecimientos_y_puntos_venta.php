<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establecimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // CAT-009: 01 sucursal/agencia, 02 casa matriz, 04 bodega, 07 predio/patio, 20 otro.
            $table->string('tipo', 2);
            $table->string('nombre', 150);
            // Código asignado por Hacienda (4 caracteres) y código interno del contribuyente.
            $table->string('cod_estable_mh', 4);
            $table->string('cod_estable', 4)->nullable();
            $table->jsonb('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'cod_estable_mh']);
        });

        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('establecimiento_id')->constrained('establecimientos')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('cod_punto_venta_mh', 4);
            $table->string('cod_punto_venta', 15)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['establecimiento_id', 'cod_punto_venta_mh']);
        });

        Rls::activar('establecimientos');
        Rls::activar('puntos_venta');
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_venta');
        Schema::dropIfExists('establecimientos');
    }
};
