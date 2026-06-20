<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $gravado = 100.00;
        $isv = round($gravado * 0.15, 2);

        return [
            'fecha'            => now(),
            'numero_factura'   => '000-001-01-'.fake()->numerify('########'),
            'proveedor_nombre' => fake()->company(),
            'proveedor_rtn'    => fake()->numerify('##############'),
            'categoria'        => 'empaques',
            'exento'           => 0,
            'gravado'          => $gravado,
            'isv'              => $isv,
            'total'            => $gravado + $isv,
        ];
    }
}
