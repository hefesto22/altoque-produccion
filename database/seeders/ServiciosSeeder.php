<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Servicio;
use Illuminate\Database\Seeder;

/**
 * Servicios del día con ventanas horarias por defecto. Idempotente;
 * los horarios se ajustan luego desde el panel.
 */
class ServiciosSeeder extends Seeder
{
    public function run(): void
    {
        $servicios = [
            ['Desayuno', 'desayuno', '06:00:00', '10:30:00', 1],
            ['Almuerzo', 'almuerzo', '11:00:00', '15:00:00', 2],
            ['Cena', 'cena', '17:00:00', '21:00:00', 3],
        ];

        foreach ($servicios as [$nombre, $slug, $inicio, $fin, $orden]) {
            Servicio::updateOrCreate(
                ['slug' => $slug],
                [
                    'nombre'      => $nombre,
                    'hora_inicio' => $inicio,
                    'hora_fin'    => $fin,
                    'orden'       => $orden,
                    'activo'      => true,
                ],
            );
        }
    }
}
