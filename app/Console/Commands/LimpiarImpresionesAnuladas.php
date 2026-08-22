<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Impresion\ColaImpresionService;
use Illuminate\Console\Command;

/**
 * Limpieza retroactiva de la cola de impresión (se corre a mano, una vez por
 * ambiente, junto con el deploy que agregó `descartarDeVenta()`).
 *
 * Lo que se anuló ANTES de ese cambio dejó su ticket en estado `impreso`, y
 * la lista "Reimprimir… (últimas 2 h)" del POS lo sigue ofreciendo: un pedido
 * anulado a un toque de volver a salir en la térmica y regresar a cocina.
 *
 * No entra al scheduler: de ahora en adelante el papel se descarta al anular.
 * Esto solo arrastra lo viejo. Es idempotente — correrlo de nuevo no hace nada.
 */
class LimpiarImpresionesAnuladas extends Command
{
    protected $signature = 'impresiones:limpiar-anuladas';

    protected $description = 'Saca de la cola de impresión el papel de los pedidos y facturas ya anulados';

    public function handle(ColaImpresionService $cola): int
    {
        $n = $cola->limpiarAnuladas();

        if ($n === 0) {
            $this->info('No había papel de anulados en la cola. Nada que sacar.');

            return self::SUCCESS;
        }

        $this->info("Se sacaron {$n} trabajo(s) de anulados. Ya no aparecen en Reimprimir ni en pendientes.");

        return self::SUCCESS;
    }
}
