<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Totales fiscales de un período mensual, calculados desde las ventas.
 * Inmutable, sin persistencia. Es lo que muestra la pantalla de
 * Declaración ISV y lo que se congela como snapshot al declarar.
 *
 * El ISV es el débito fiscal del período (ISV cobrado en todas las
 * ventas, recibo o factura — toda venta guarda su desglose).
 */
final readonly class ResumenPeriodo
{
    public function __construct(
        public int $anio,
        public int $mes,
        public int $cantidadVentas,
        public float $gravado,
        public float $exento,
        public float $isv,
        public float $total,
        public float $recibosTotal,
        public float $facturasTotal,
        // Crédito fiscal (ISV de compras) y neto a pagar (débito − crédito).
        public float $creditoFiscal = 0.0,
        public float $isvAPagar = 0.0,
    ) {}
}
