<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Combo;
use App\Models\Producto;
use Illuminate\Database\Seeder;

/**
 * Menú real del restaurante (tomado del flyer de "Al Toque Comida Buffet")
 * + reglas de precio por nivel.
 *
 * Precios individuales: Res L.70 · Pollo/Cerdo L.60 · Complemento L.30.
 * Reglas de precio (proteína + N complementos → precio):
 *   Pollo/Cerdo +2=100 +3=125 · Res +2=110 +3=135 · Pescado +2=105 +3=115.
 *
 * Toda la comida grava ISV 15% (confirmado con el contador). Idempotente
 * por nombre — se puede re-correr sin duplicar. Los tiers los carga
 * TierSeeder (corre antes).
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->proteinas();
        $this->complementos();
        $this->bebidas();
        $this->reglasDePrecio();
    }

    private function proteinas(): void
    {
        // [nombre, tier, precio individual]
        $proteinas = [
            ['Pollo en salsa Alfredo', 'pollo_cerdo', 60.00],
            ['Chuleta a la plancha', 'pollo_cerdo', 60.00],
            ['Chicharrones de cerdo', 'pollo_cerdo', 60.00],
            ['Pechuga a la plancha', 'pollo_cerdo', 60.00],
            ['Carne molida con papas', 'res', 70.00],
        ];

        foreach ($proteinas as [$nombre, $tier, $precio]) {
            Producto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria'  => 'proteina',
                    'tier_combo' => $tier,
                    'precio'     => $precio,
                    'grava_isv'  => true,
                    'activo'     => true,
                ],
            );
        }
    }

    private function complementos(): void
    {
        $complementos = [
            'Arroz imperial',
            'Vegetales al vapor',
            'Ensalada de lechuga',
            'Puré de papas',
            'Remolacha cocida',
            'Habichuela con huevo',
            'Frijoles guisados',
            'Queso fresco',
            'Tajadas de plátano verde',
        ];

        foreach ($complementos as $nombre) {
            Producto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria'  => 'complemento',
                    'tier_combo' => null,
                    'precio'     => 30.00,
                    'grava_isv'  => true,
                    'activo'     => true,
                ],
            );
        }
    }

    private function bebidas(): void
    {
        // Bebidas gravan ISV siempre. Precios editables desde el panel.
        $bebidas = [
            'Fresco natural'  => 25.00,
            'Gaseosa'         => 20.00,
            'Agua purificada' => 15.00,
        ];

        foreach ($bebidas as $nombre => $precio) {
            Producto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria'  => 'bebida',
                    'tier_combo' => null,
                    'precio'     => $precio,
                    'grava_isv'  => true,
                    'activo'     => true,
                ],
            );
        }
    }

    private function reglasDePrecio(): void
    {
        // [tier, nº de complementos, precio del combo]
        $reglas = [
            ['pollo_cerdo', 2, 100.00],
            ['pollo_cerdo', 3, 125.00],
            ['res', 2, 110.00],
            ['res', 3, 135.00],
            ['pescado', 2, 105.00],
            ['pescado', 3, 115.00],
        ];

        foreach ($reglas as [$tier, $complementos, $precio]) {
            Combo::updateOrCreate(
                ['tier' => $tier, 'complementos' => $complementos],
                ['precio' => $precio, 'activo' => true],
            );
        }
    }
}
