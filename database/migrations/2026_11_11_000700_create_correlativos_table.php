<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Último correlativo usado por ámbito y año. Se asigna con SELECT … FOR UPDATE
        // dentro de una transacción; un número entregado nunca se reutiliza.
        Schema::create('correlativos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // Ámbito según el modo de la empresa (ver App\Dte\Correlativos\Correlativos).
            $table->string('clave', 60);
            $table->smallInteger('anio');
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'clave', 'anio']);
        });

        Rls::activar('correlativos');
    }

    public function down(): void
    {
        Schema::dropIfExists('correlativos');
    }
};
