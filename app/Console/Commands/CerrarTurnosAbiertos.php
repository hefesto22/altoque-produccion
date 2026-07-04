<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CorteCaja;
use App\Services\Caja\CorteCajaService;
use Illuminate\Console\Command;

/**
 * Cierre automático de turnos (scheduler, 11:59 PM Honduras): cierra todo
 * turno que quedó abierto, sin efectivo contado — el corte queda marcado
 * "por revisar" en Cortes De Caja para que un administrador lo corrija.
 */
class CerrarTurnosAbiertos extends Command
{
    protected $signature = 'caja:cierre-automatico';

    protected $description = 'Cierra los turnos de caja que quedaron abiertos y los deja pendientes de revisión';

    public function handle(CorteCajaService $caja): int
    {
        $abiertos = CorteCaja::query()->where('estado', 'abierto')->get();

        if ($abiertos->isEmpty()) {
            $this->info('Sin turnos abiertos. Nada que cerrar.');

            return self::SUCCESS;
        }

        foreach ($abiertos as $corte) {
            $caja->cerrar(
                $corte,
                efectivoContado: null,
                notas: 'Cierre automático de fin de día — pendiente de revisión.',
                automatico: true,
            );

            activity()->performedOn($corte)->log('corte_cierre_automatico');
            $this->info("Corte #{$corte->id} ({$corte->cajero?->name}) cerrado automáticamente.");
        }

        return self::SUCCESS;
    }
}
