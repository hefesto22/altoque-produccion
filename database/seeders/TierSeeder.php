<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tier;
use Illuminate\Database\Seeder;

/**
 * Niveles de precio (tiers) base del restaurante. Agrupan proteínas que
 * comparten precio de combo. Idempotente por código.
 *
 * El restaurante puede crear más niveles desde el panel (Menú → Niveles
 * de Precio) y asignarlos a sus proteínas.
 */
class TierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['pollo_cerdo', 'Pollo / Cerdo', 1],
            ['res', 'Res', 2],
            ['pescado', 'Pescado', 3],
        ];

        foreach ($tiers as [$codigo, $nombre, $orden]) {
            Tier::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'orden' => $orden, 'activo' => true],
            );
        }
    }
}
