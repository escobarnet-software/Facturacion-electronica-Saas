<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            // dueno | contador | cajero | consulta
            $table->string('rol', 20);
            // Solo para cajeros: el punto de venta desde el que emiten.
            $table->foreignId('punto_venta_id')->nullable()->constrained('puntos_venta')->nullOnDelete();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('usuario_id');
            $table->unique(['empresa_id', 'usuario_id']);
        });

        // Un usuario puede LEER sus propias membresías (para elegir la empresa activa).
        Rls::activar('empresa_usuario', lecturaExtra: 'usuario_id = '.Rls::USUARIO_ACTUAL);

        // Empresas: se ve la empresa activa y aquellas a las que pertenece el usuario.
        // Crear una empresa nueva requiere Tenancy::sinAislamiento() (operación privilegiada).
        Rls::activar('empresas', 'id', 'id IN (SELECT eu.empresa_id FROM empresa_usuario eu WHERE eu.usuario_id = '.Rls::USUARIO_ACTUAL.')');
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_usuario');
    }
};
