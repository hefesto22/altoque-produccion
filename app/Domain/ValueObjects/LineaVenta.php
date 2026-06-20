<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Snapshot inmutable de una línea cobrada en el momento de la venta.
 *
 * El nombre, el precio unitario y el flag grava_isv se CONGELAN aquí:
 * si mañana sube el precio del pollo o cambia su tratamiento fiscal,
 * esta venta histórica no se altera. Nunca se recalcula una venta
 * pasada leyendo el producto actual.
 */
final readonly class LineaVenta
{
    /**
     * @param array<int, string> $detalle Complementos del plato (nombres), para el ticket.
     */
    public function __construct(
        public int $productoId,
        public string $nombre,
        public float $precioUnitario,
        public int $cantidad,
        public bool $gravaIsv,
        public array $detalle = [],
    ) {}

    /** Importe de la línea (precio × cantidad), redondeado a 2 decimales. */
    public function importe(): float
    {
        return round($this->precioUnitario * $this->cantidad, 2);
    }
}
