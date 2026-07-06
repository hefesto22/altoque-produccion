<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cai;
use App\Models\EmpresaSetting;
use Illuminate\Database\Seeder;

/**
 * Datos fiscales REALES de Al Toque (documento SAR-927, solicitud
 * 03/07/2026). Deja producción al 100%: emisor real + CAI real activo.
 *
 * Idempotente: se puede correr varias veces sin duplicar nada. Si el
 * CAI real ya existe, solo garantiza que quede activo (salvo agotado
 * o vencido) y desactiva cualquier otro rango.
 *
 * ⚠️ Correr SOLO en producción (altoque.cloud). En pruebas el CAI de
 * prueba es suficiente y evita confundir documentos con el rango real.
 */
class ProduccionAlToqueSeeder extends Seeder
{
    /** CAI real autorizado por el SAR (autoimpresor 003, factura 01). */
    private const CAI_REAL = '55C296-57CC36-7833E0-63BE03-0909C1-C8';

    public function run(): void
    {
        // ── Emisor real (documento de autorización SAR) ──────────────────
        // El nombre comercial de cara al cliente es la marca del restaurante;
        // la razón social y el RTN son los registrados ante el SAR.
        $empresa = EmpresaSetting::query()->first() ?? new EmpresaSetting;

        $empresa->fill([
            'razon_social'     => 'BLANCA AZUCENA HERNANDEZ FLORES',
            'nombre_comercial' => 'Restaurante Al Toque',
            'rtn'              => '18061979009059',
            'direccion'        => 'Barrio Mercedes, media cuadra del Mercado Municipal, Santa Rosa de Copán, Copán',
            'telefono'         => '9807-1926',
            'correo'           => 'blancahernandez1979@hotmail.com',
        ])->save(); // el hook saved() del modelo invalida la cache del singleton

        $this->command?->info('✓ Emisor real: RESTAURANTE AL TOQUE · RTN 18061979009059');

        // ── CAI real (rango 000-003-01-00000001 al 00001000) ────────────
        $cai = Cai::firstOrCreate(
            ['codigo' => self::CAI_REAL],
            [
                'establecimiento'      => '000',
                'punto_emision'        => '003',
                'tipo_documento'       => '01',
                'correlativo_desde'    => 1,
                'correlativo_hasta'    => 1000,
                'correlativo_actual'   => 0,
                'fecha_autorizacion'   => '2026-07-03',
                'fecha_limite_emision' => '2027-07-03',
                'estado'               => 'activo',
            ],
        );

        // Garantiza que el real quede activo y sea el ÚNICO activo
        // (el modelo exige un solo CAI activo para emitir).
        if (! in_array($cai->estado, ['agotado', 'vencido'], true) && $cai->estado !== 'activo') {
            $cai->update(['estado' => 'activo']);
        }

        Cai::query()
            ->where('id', '!=', $cai->id)
            ->where('estado', 'activo')
            ->update(['estado' => 'inactivo']);

        $this->command?->info("✓ CAI real activo: {$cai->prefijo()} · rango 1–1000 · vence 03/07/2027");
    }
}
