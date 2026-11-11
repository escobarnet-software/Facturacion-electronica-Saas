<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token secreto del buzón de entrada: el proveedor de correo entrante reenvía a /buzon/{token}.
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('buzon_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('buzon_token'));
    }
};
