<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CuentaMovimiento;
use App\Models\Venta;
use Illuminate\Contracts\View\View;

/**
 * Nota de consumo (80mm) para el cliente de una cuenta prepago.
 *
 * NO es un documento fiscal y lo dice en letras grandes: esa comida ya se
 * cobró y se facturó el día que la empresa hizo su depósito. Sirve para que
 * la persona que vino a comer se lleve el comprobante de qué consumió y de
 * cuánto le queda a su empresa.
 *
 * HTML directo, igual que la comanda: imprime al instante desde el POS.
 */
class NotaConsumoController extends Controller
{
    public function show(Venta $venta): View
    {
        abort_unless($venta->esConsumoDeCuenta(), 404);

        $venta->load(['items', 'cajero:id,name']);

        $movimiento = CuentaMovimiento::query()
            ->where('venta_id', $venta->id)
            ->where('tipo', 'consumo')
            ->with('cuenta:id,nombre,saldo,permite_credito,limite_credito')
            ->first();

        return view('tickets.nota-consumo', [
            'venta'      => $venta,
            'movimiento' => $movimiento,
            'cuenta'     => $movimiento?->cuenta,
        ]);
    }
}
