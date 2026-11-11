<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->timestamp('entregado_at')->nullable();
            $table->string('entregado_a', 100)->nullable();
        });
        Schema::table('empresas', function (Blueprint $table) {
            // Logo para la representación gráfica (ruta en el disco del archivo).
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dtes', fn (Blueprint $t) => $t->dropColumn(['entregado_at', 'entregado_a']));
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('logo_path'));
    }
};
