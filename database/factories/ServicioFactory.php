<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Servicio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Servicio>
 */
class ServicioFactory extends Factory
{
    protected $model = Servicio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre'      => 'Almuerzo',
            'slug'        => 'almuerzo',
            'hora_inicio' => '11:00:00',
            'hora_fin'    => '15:00:00',
            'orden'       => 2,
            'activo'      => true,
        ];
    }
}
