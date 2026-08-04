<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Comanda;
use Illuminate\Contracts\View\View;

/**
 * Ticket imprimible de la comanda (80mm) para la cocina: qué preparar,
 * de qué orden es y si está pendiente de pago. HTML directo (no PDF):
 * imprime al instante desde el iframe del POS sin pasar por Chromium —
 * en caja la velocidad manda.
 */
class ComandaTicketController extends Controller
{
    public function show(Comanda $comanda): View
    {
        // cajero_id es obligatorio en el select: sin la FK, la relación
        // cajero() no resuelve y el ticket saldría sin el "Atendió".
        $comanda->load(['venta:id,numero_orden,total,pagada,forma_pago,cajero_id', 'venta.cajero:id,name']);

        return view('tickets.comanda', ['comanda' => $comanda]);
    }
}
