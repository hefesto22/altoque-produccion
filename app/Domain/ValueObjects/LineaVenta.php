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
 *
 * DESCUENTO DE COMBO:
 *   - `precioListaUnitario` = lo que costaría à la carte (suma de los
 *     precios individuales). Si es null, no hay descuento (lista = precio).
 *   - `precioUnitario` = lo realmente cobrado (el precio del combo).
 *   - descuento = (lista − cobrado), nunca negativo.
 *
 *   `componentes` lleva cada producto del plato con su precio de lista y
 *   su flag, para prorratear el descuento entre gravado/exento y para
 *   detallar la factura producto por producto. Si está vacío, la línea
 *   se trata como un solo bucket según `gravaIsv` (bebida/extra suelto).
 */
final readonly class LineaVenta
{
    /**
     * @param array<int, string> $detalle Complementos del plato (nombres), para el ticket/cocina.
     * @param array<int, ComponenteLinea> $componentes Desglose con precio de lista y flag por producto.
     */
    public function __construct(
        public int $productoId,
        public string $nombre,
        public float $precioUnitario,
        public int $cantidad,
        public bool $gravaIsv,
        public array $detalle = [],
        public ?float $precioListaUnitario = null,
        public array $componentes = [],
        public string $nota = '',
    ) {}

    /** Importe cobrado de la línea (precio × cantidad), redondeado a 2 decimales. */
    public function importe(): float
    {
        return round($this->precioUnitario * $this->cantidad, 2);
    }

    /** Importe à la carte (precio de lista × cantidad). Sin lista => igual al cobrado. */
    public function subtotalLista(): float
    {
        $lista = $this->precioListaUnitario ?? $this->precioUnitario;

        return round($lista * $this->cantidad, 2);
    }

    /** Descuento de la línea (lista − cobrado). Nunca negativo. */
    public function descuento(): float
    {
        return round(max(0.0, $this->subtotalLista() - $this->importe()), 2);
    }

    /**
     * Reparte el importe COBRADO (ya con descuento aplicado) entre los
     * componentes, según el peso de su precio de lista, asignando cada
     * parte a gravado o exento según el flag del componente.
     *
     * El último componente absorbe el redondeo, de modo que la suma de
     * las partes es EXACTAMENTE el importe cobrado (cuadra al centavo).
     *
     * Sin componentes: una sola parte con el flag de la línea.
     *
     * @return array<int, array{neto: float, grava: bool}>
     */
    public function repartoNeto(): array
    {
        $neto = $this->importe();

        if ($this->componentes === []) {
            return [['neto' => $neto, 'grava' => $this->gravaIsv]];
        }

        $componentes = array_values($this->componentes);
        $baseLista = array_sum(array_map(
            static fn (ComponenteLinea $c): float => $c->importeLista(),
            $componentes,
        ));

        $n = count($componentes);
        $acumulado = 0.0;
        $reparto = [];

        foreach ($componentes as $idx => $c) {
            if ($idx === $n - 1) {
                $monto = round($neto - $acumulado, 2); // el último cuadra el redondeo
            } else {
                $peso = $baseLista > 0.0 ? ($c->importeLista() / $baseLista) : (1 / $n);
                $monto = round($neto * $peso, 2);
                $acumulado += $monto;
            }

            $reparto[] = ['neto' => $monto, 'grava' => $c->gravaIsv];
        }

        return $reparto;
    }
}
