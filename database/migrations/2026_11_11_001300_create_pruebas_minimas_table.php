<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avance de las pruebas mínimas por empresa y tipo: permite reanudar si la ejecución se corta.
        Schema::create('pruebas_minimas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo', 30);               // código de DTE (01…15) o evento (invalidacion…)
            $table->unsignedInteger('requeridas');
            $table->unsignedInteger('exitosas')->default(0);
            $table->unsignedInteger('fallidas')->default(0);
            $table->string('estado', 20)->default('pendiente'); // pendiente | en_curso | completada | detenida
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'tipo']);
        });

        Rls::activar('pruebas_minimas');
    }

    public function down(): void
    {
        Schema::dropIfExists('pruebas_minimas');
    }
};
