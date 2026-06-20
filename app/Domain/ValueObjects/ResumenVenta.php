<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Desglose fiscal calculado de una venta. Inmutable, sin lógica de
 * persistencia. Es la salida del CalculadorVenta y la entrada para
 * registrar la venta o emitir la factura.
 *
 *  - gravado: base gravable (sin ISV)
 *  - exento:  importe de líneas que no gravan
 *  - isv:     impuesto sobre las líneas gravadas
 *  - total:   lo que paga el cliente (gravado + isv + exento)
 */
final readonly class ResumenVenta
{
    public function __construct(
        public float $gravado,
        public float $exento,
        public float $isv,
        public float $total,
    ) {}
}
