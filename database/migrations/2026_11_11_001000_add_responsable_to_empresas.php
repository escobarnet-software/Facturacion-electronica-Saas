<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Responsable ante Hacienda: lo exigen el Evento de Contingencia y la invalidación.
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('responsable_nombre', 100)->nullable();
            $table->string('responsable_tipo_doc', 2)->nullable();   // CAT-022
            $table->string('responsable_num_doc', 25)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['responsable_nombre', 'responsable_tipo_doc', 'responsable_num_doc']);
        });
    }
};
