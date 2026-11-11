<?php

namespace Database\Factories;

use App\Models\Establecimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Establecimiento>
 */
class EstablecimientoFactory extends Factory
{
    protected $model = Establecimiento::class;

    public function definition(): array
    {
        return [
            'tipo' => '02',
            'nombre' => 'Casa matriz',
            'cod_estable_mh' => 'M001',
            'cod_estable' => 'M001',
        ];
    }
}
