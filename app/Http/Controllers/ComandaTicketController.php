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
        $comanda->load('venta:id,numero_orden,total,pagada,forma_pago');

        return view('tickets.comanda', ['comanda' => $comanda]);
    }
}
