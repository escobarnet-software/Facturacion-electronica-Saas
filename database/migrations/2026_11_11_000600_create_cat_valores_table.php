<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogos oficiales de Hacienda (CAT-001 a CAT-033), en UNA tabla genérica.
        // Son datos de referencia comunes a todas las empresas: no llevan empresa_id ni RLS.
        Schema::create('cat_valores', function (Blueprint $table) {
            $table->id();
            $table->string('catalogo', 7);          // CAT-013
            $table->string('codigo', 20);           // siempre texto, tal como lo exige el esquema
            $table->string('valor', 500);
            // Agrupador: el departamento (CAT-012) para municipios (CAT-013) y distritos
            // (CAT-008); el número de sección para tributos (CAT-015). '' si no aplica.
            $table->string('padre', 20)->default('');
            // Sección dentro del catálogo (CAT-015: 1 resumen, 2 cuerpo, 3 informativos; CAT-019: sector).
            $table->string('seccion', 300)->nullable();
            $table->boolean('vigente')->default(true);
            $table->string('version', 20);          // versión del Excel oficial (por ejemplo, "1.1")
            $table->timestamps();

            $table->unique(['catalogo', 'padre', 'codigo']);
            $table->index(['catalogo', 'vigente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_valores');
    }
};
