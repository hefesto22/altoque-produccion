<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Combo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Combo>
 */
class ComboFactory extends Factory
{
    protected $model = Combo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tier'         => 'pollo_cerdo',
            'complementos' => 2,
            'precio'       => 100.00,
            'activo'       => true,
        ];
    }
}
