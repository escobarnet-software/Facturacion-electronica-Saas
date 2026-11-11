<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DTE que la empresa RECIBE de sus proveedores (para el libro de compras y el crédito fiscal).
        Schema::create('dte_recibidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->char('tipo', 2);
            $table->char('codigo_generacion', 36);
            $table->string('numero_control', 31);
            $table->date('fecha_emision');
            $table->string('emisor_nit', 14);
            $table->string('emisor_nrc', 8)->nullable();
            $table->string('emisor_nombre', 250);
            $table->string('sello_recibido', 40);
            $table->decimal('total_no_suj', 18, 2)->default(0);
            $table->decimal('total_exenta', 18, 2)->default(0);
            $table->decimal('total_gravada', 18, 2)->default(0);
            $table->decimal('total_iva', 18, 2)->default(0);
            $table->decimal('iva_percibido', 18, 2)->default(0);
            $table->decimal('iva_retenido', 18, 2)->default(0);
            $table->decimal('total_pagar', 18, 2)->default(0);
            $table->text('json_texto');                    // el archivo tal como llegó
            $table->jsonb('json_original');
            $table->boolean('firma_coincide');             // el JWS contiene exactamente este documento
            $table->boolean('verificado_publico')->nullable(); // consulta pública de Hacienda (opcional)
            $table->string('origen', 20);                  // subida | correo
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'codigo_generacion']);
            $table->index(['empresa_id', 'fecha_emision']);
        });

        Rls::activar('dte_recibidos');
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_recibidos');
    }
};
