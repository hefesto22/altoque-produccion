<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CorteCaja;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Ticket imprimible del corte de caja (80mm): resumen del turno para
 * archivar junto al efectivo contado. HTML directo (no PDF): imprime al
 * instante desde el iframe del panel, igual que factura y comanda.
 */
class CorteTicketController extends Controller
{
    public function show(CorteCaja $corte): View
    {
        $corte->load('cajero:id,name');

        // Desglose por banco desde venta_pagos: el pago mixto reparte una
        // venta entre varios métodos — mismo criterio que el cierre del POS.
        $porBanco = static fn (string $metodo): array => array_map(
            static fn ($r): array => ['banco' => (string) $r->banco, 'total' => (float) $r->total],
            DB::select('
                SELECT vp.banco, sum(vp.monto) AS total
                FROM venta_pagos vp
                JOIN ventas v ON v.id = vp.venta_id
                WHERE v.corte_caja_id = ? AND v.pagada = true
                  AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id AND f.anulada = true)
                  AND vp.metodo = ? AND vp.banco IS NOT NULL
                GROUP BY vp.banco ORDER BY vp.banco
            ', [$corte->id, $metodo]),
        );

        // Lo que la empresa debe a repartidores: viaje de domicilios pagados
        // por transferencia (los de efectivo ya los entrega el repartidor).
        $domViajeTransfer = (float) Venta::query()
            ->where('corte_caja_id', $corte->id)
            ->cuentaEnCaja()
            ->where('tipo_orden', 'domicilio')
            ->where('forma_pago', 'transferencia')
            ->sum('costo_viaje');

        return view('tickets.corte', [
            'corte'            => $corte,
            'tarjetaBanco'     => $porBanco('tarjeta'),
            'transferBanco'    => $porBanco('transferencia'),
            'domViajeTransfer' => $domViajeTransfer,
        ]);
    }
}
