<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credenciales de la API de Hacienda y de la llave privada de cada empresa.
        // Pertenecen al cliente: van cifradas con DTE_SECRETS_KEY (nunca con APP_KEY)
        // y jamás se muestran de vuelta. El usuario/contraseña del portal DGII NO se guarda.
        Schema::create('empresa_credenciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->char('ambiente', 2);
            // Usuario de la API (el NIT del emisor).
            $table->string('usuario_api', 20);
            $table->text('pwd_api');
            $table->text('pwd_privada');
            $table->string('certificado_sha256', 64)->nullable();
            $table->timestamp('certificado_cargado_at')->nullable();
            $table->unsignedSmallInteger('logins_fallidos')->default(0);
            $table->timestamp('bloqueado_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'ambiente']);
        });

        Rls::activar('empresa_credenciales');
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_credenciales');
    }
};
