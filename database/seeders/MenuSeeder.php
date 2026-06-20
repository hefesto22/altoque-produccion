<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Combo;
use App\Models\Producto;
use Illuminate\Database\Seeder;

/**
 * Menú real del restaurante (tomado del flyer del día) + reglas de combo.
 *
 * Precios individuales: Res L.70 · Pollo/Cerdo L.60 · Complemento L.30.
 * Combos: Pollo/Cerdo +2 = 100 · Res +2 = 110 · Pollo/Cerdo +3 = 125 · Res +3 = 135.
 *
 * Idempotente (updateOrCreate): se puede re-correr sin duplicar.
 *
 * NOTA: las proteínas del día son tier pollo_cerdo. El tier 'res' queda
 * con sus combos cargados para cuando haya un plato de res. Las bebidas
 * son placeholders editables — confirmar precios reales con Mauricio.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->proteinas();
        $this->complementos();
        $this->bebidas();
        $this->combos();
    }

    private function proteinas(): void
    {
        $proteinas = [
            'Lomo de cerdo en salsa de mandarina',
            'Chicharrones de cerdo',
            'Pollo en teriyaki al horno',
            'Alitas en barbacoa',
        ];

        foreach ($proteinas as $nombre) {
            Producto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria'  => 'proteina',
                    'tier_combo' => 'pollo_cerdo',
                    'precio'     => 60.00,
                    // Servicio de restaurante: grava 15% (decisión a confirmar con el contador).
                    'grava_isv' => true,
                    'activo'    => true,
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
            'Ensalada de papa estilo casero',
            'Remolacha cocida',
            'Plátano cocido',
            'Tajadas de plátano verde',
            'Frijoles guisados',
            'Queso',
        ];

        foreach ($complementos as $nombre) {
            Producto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria'  => 'complemento',
                    'tier_combo' => null,
                    'precio'     => 30.00,
                    // Servicio de restaurante: grava 15% (a confirmar con el contador).
                    'grava_isv' => true,
                    'activo'    => true,
                ],
            );
        }
    }

    private function bebidas(): void
    {
        // Placeholders — confirmar lista y precios reales. Las bebidas gravan ISV.
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

    private function combos(): void
    {
        $combos = [
            ['pollo_cerdo', 2, 100.00],
            ['res', 2, 110.00],
            ['pollo_cerdo', 3, 125.00],
            ['res', 3, 135.00],
        ];

        foreach ($combos as [$tier, $complementos, $precio]) {
            Combo::updateOrCreate(
                ['tier' => $tier, 'complementos' => $complementos],
                ['precio' => $precio, 'activo' => true],
            );
        }
    }
}
