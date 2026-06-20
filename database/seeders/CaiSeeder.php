<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cai;
use Illuminate\Database\Seeder;

/**
 * CAI genérico SOLO para pruebas (no es un CAI real del SAR). Permite
 * emitir facturas de prueba desde el POS sin esperar una autorización
 * real. Idempotente y bloqueado en producción.
 */
class CaiSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Producción: se omite el CAI de prueba.');

            return;
        }

        $cai = Cai::updateOrCreate(
            ['codigo' => 'A1B2C3-D4E5F6-A1B2C3-D4E5F6-A1B2C3-01'],
            [
                'establecimiento'      => '000',
                'punto_emision'        => '001',
                'tipo_documento'       => '01',
                'correlativo_desde'    => 1,
                'correlativo_hasta'    => 500,
                'correlativo_actual'   => 0,
                'fecha_autorizacion'   => now()->subDay(),
                'fecha_limite_emision' => now()->addMonths(11),
                'estado'               => 'activo',
            ],
        );

        $this->command?->info("✓ CAI de prueba activo: {$cai->prefijo()} · rango 1–500");
    }
}
