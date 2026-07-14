<?php

declare(strict_types=1);

namespace App\Services\Eventos;

use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Calcula los totales de una cotización de evento con el MISMO criterio
 * fiscal que CalculadorVenta (confirmado con Mauricio): el precio
 * personalizado ya INCLUYE el ISV; al totalizar se desglosa, no se suma.
 *
 *   Ítem que grava:  base = neto / (1 + tasa) ; isv = neto − base
 *   Ítem exento:     todo el neto va a 'exento'
 *
 * El descuento global se prorratea entre los ítems por peso de su
 * importe (el último ítem absorbe el residuo de redondeo), de modo que
 * el desglose cuadra al centavo con el total mostrado al cliente.
 *
 * La tasa nunca se hardcodea: viene de config('honduras.impuestos...').
 */
final class CotizadorEventos
{
    public function __construct(private readonly float $tasaIsv) {}

    public static function make(): self
    {
        return new self((float) config('honduras.impuestos.isv.tasa_general', 0.15));
    }

    /** Recalcula y persiste los totales a partir de los ítems guardados. */
    public function recalcular(Cotizacion $cotizacion): void
    {
        /** @var Collection<int, CotizacionItem> $items */
        $items = $cotizacion->items()->get();

        $subtotal = round(
            $items->sum(static fn (CotizacionItem $i): float => $i->importe()),
            2,
        );

        // El descuento nunca supera el subtotal (una cotización no puede dar negativo).
        $descuento = round(min(max((float) $cotizacion->descuento, 0.0), $subtotal), 2);

        $gravadoBase = 0.0;
        $isv = 0.0;
        $exento = 0.0;
        $descuentoRestante = $descuento;
        $ultimo = $items->count() - 1;

        foreach ($items->values() as $idx => $item) {
            $bruto = $item->importe();

            // Prorrateo por peso; el último ítem absorbe el residuo de redondeo.
            $desc = $idx === $ultimo || $subtotal <= 0
                ? $descuentoRestante
                : round($descuento * ($bruto / $subtotal), 2);
            $descuentoRestante = round($descuentoRestante - $desc, 2);

            $neto = round($bruto - $desc, 2);

            if ($item->grava_isv) {
                $base = round($neto / (1 + $this->tasaIsv), 2);
                $gravadoBase += $base;
                $isv += round($neto - $base, 2);
            } else {
                $exento += $neto;
            }
        }

        $cotizacion->forceFill([
            'subtotal'  => round($subtotal, 2),
            'descuento' => $descuento,
            'gravado'   => round($gravadoBase, 2),
            'exento'    => round($exento, 2),
            'isv'       => round($isv, 2),
            'total'     => round($gravadoBase + $isv + $exento, 2),
        ])->save();
    }
}
