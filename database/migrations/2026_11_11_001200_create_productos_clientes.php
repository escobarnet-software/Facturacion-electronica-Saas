<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // Actividad económica principal (CAT-019) que se informa como emisor.
            $table->string('cod_actividad', 6)->nullable();
            $table->string('desc_actividad', 150)->nullable();
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // Debe coincidir con el código del F-983 si la empresa lo presenta.
            $table->string('codigo', 25)->nullable();
            $table->string('codigo_barras', 50)->nullable();
            $table->string('descripcion', 1000);
            // Precio SIN IVA (8 decimales). En la Factura se muestra con IVA incluido.
            $table->decimal('precio', 20, 8);
            $table->string('tipo_venta', 20)->default('gravada'); // gravada | exenta | no_sujeta
            $table->smallInteger('tipo_item')->default(1);        // CAT-011
            $table->smallInteger('uni_medida')->default(59);      // CAT-014
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'codigo_barras']);
            $table->unique(['empresa_id', 'codigo']);
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo_documento', 2);                  // CAT-022
            $table->string('num_documento', 20);
            $table->string('nrc', 8)->nullable();                 // contribuyente IVA → puede recibir CCF
            $table->string('nombre', 250);
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('cod_actividad', 6)->nullable();
            $table->string('desc_actividad', 150)->nullable();
            $table->jsonb('direccion')->nullable();               // departamento, municipio, distrito, complemento
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->boolean('gran_contribuyente')->default(false);
            $table->timestamps();

            $table->index('empresa_id');
            $table->unique(['empresa_id', 'tipo_documento', 'num_documento']);
        });

        Rls::activar('productos');
        Rls::activar('clientes');
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('productos');
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn(['cod_actividad', 'desc_actividad']));
    }
};
