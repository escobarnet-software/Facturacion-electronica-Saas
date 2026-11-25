<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotencia de la API: la misma clave nunca genera dos documentos.
        Schema::create('api_idempotencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('clave', 255);
            $table->char('huella', 64);                 // sha256 de método + ruta + cuerpo
            $table->string('estado', 20);               // procesando | completado
            $table->unsignedSmallInteger('status')->nullable();
            $table->jsonb('respuesta')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'clave']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secreto');                    // cifrado con DTE_SECRETS_KEY
            $table->jsonb('eventos');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
        });

        Schema::create('webhook_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('evento', 40);
            $table->text('cuerpo');                     // exactamente lo que se firma y se envía
            $table->string('estado', 20)->default('pendiente'); // pendiente | entregado | fallido
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->unsignedSmallInteger('ultimo_status')->nullable();
            $table->string('ultimo_error', 500)->nullable();
            $table->timestamp('entregado_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'estado']);
        });

        foreach (['api_idempotencia', 'webhooks', 'webhook_entregas'] as $tabla) {
            Rls::activar($tabla);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_entregas');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_idempotencia');
    }
};
