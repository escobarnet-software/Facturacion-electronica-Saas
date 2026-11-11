<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Empresas de prueba con NIT ficticio. Crear una empresa es una operación de
 * plataforma: en pruebas, usar dentro de Tenancy::sinAislamiento().
 *
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        return [
            'nit' => fake()->unique()->numerify('0614##########'),
            'nrc' => fake()->numerify('######'),
            'razon_social' => fake()->company(),
            'nombre_comercial' => fake()->company(),
            'ambiente' => '00',
            'correo' => fake()->companyEmail(),
            'telefono' => '22223333',
            'responsable_nombre' => 'Responsable de Prueba',
            'responsable_tipo_doc' => '13',
            'responsable_num_doc' => '012345678',
        ];
    }
}
