<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Desglose fiscal calculado de una venta. Inmutable, sin lógica de
 * persistencia. Es la salida del CalculadorVenta y la entrada para
 * registrar la venta o emitir la factura.
 *
 *  - subtotalLista: importe à la carte antes de descuento (Σ precios de lista)
 *  - descuento:     rebaja por combo/promoción (subtotalLista − total)
 *  - gravado:       base gravable (sin ISV), ya neta de descuento
 *  - exento:        importe de líneas que no gravan, ya neto de descuento
 *  - isv:           impuesto sobre las líneas gravadas (sobre el neto)
 *  - total:         lo que paga el cliente (gravado + isv + exento)
 *
 * Invariante: subtotalLista == total + descuento.
 */
final readonly class ResumenVenta
{
    public function __construct(
        public float $gravado,
        public float $exento,
        public float $isv,
        public float $total,
        public float $subtotalLista = 0.0,
        public float $descuento = 0.0,
    ) {}
}
