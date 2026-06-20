<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre'    => fake()->words(2, true),
            'categoria' => fake()->randomElement(['proteina', 'complemento', 'extra']),
            'precio'    => fake()->randomFloat(2, 20, 200),
            'grava_isv' => false,
            'activo'    => true,
        ];
    }

    /** Bebida: grava ISV siempre. */
    public function bebida(): static
    {
        return $this->state(fn (): array => [
            'categoria' => 'bebida',
            'grava_isv' => true,
        ]);
    }

    public function proteina(): static
    {
        return $this->state(fn (): array => [
            'categoria' => 'proteina',
            'grava_isv' => false,
        ]);
    }
}
