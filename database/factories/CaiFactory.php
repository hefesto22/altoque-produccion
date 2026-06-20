<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cai>
 */
class CaiFactory extends Factory
{
    protected $model = Cai::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'codigo'               => 'A1B2C3-D4E5F6-A1B2C3-D4E5F6-A1B2C3-01',
            'establecimiento'      => '000',
            'punto_emision'        => '001',
            'tipo_documento'       => '01',
            'correlativo_desde'    => 1,
            'correlativo_hasta'    => 1000,
            'correlativo_actual'   => 0,
            'fecha_autorizacion'   => now()->subMonth(),
            'fecha_limite_emision' => now()->addMonths(11),
            'estado'               => 'activo',
        ];
    }

    /** Rango con un solo número disponible (para probar agotamiento). */
    public function casiAgotado(): static
    {
        return $this->state(fn (): array => [
            'correlativo_desde'  => 1,
            'correlativo_hasta'  => 1,
            'correlativo_actual' => 0,
        ]);
    }
}
