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
            'tipo_documento'   => 'factura',
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

    /** Compra SIN factura del proveedor: no acredita ISV. */
    public function recibo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tipo_documento' => 'recibo',
            'numero_factura' => null,
            'proveedor_rtn'  => null,
            'exento'         => $attributes['total'] ?? 115,
            'gravado'        => 0,
            'isv'            => 0,
        ]);
    }
}
