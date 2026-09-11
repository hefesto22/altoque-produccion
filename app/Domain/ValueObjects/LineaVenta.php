<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\VentaItem;

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

    /**
     * Reconstruye la línea desde su snapshot congelado en `venta_items`.
     *
     * Hace falta para re-tarifar un pedido que ya estaba registrado — el caso
     * del pendiente que al cobrarlo resulta ser una compra exonerada. Se lee
     * del snapshot y NO del producto actual: la venta histórica manda.
     *
     * @param array<string, mixed>|VentaItem $item
     */
    public static function desdeItem(mixed $item): self
    {
        $dato = static fn (string $clave): mixed => is_array($item) ? ($item[$clave] ?? null) : $item->{$clave};

        $componentes = $dato('componentes');
        $detalle = $dato('detalle');
        $lista = $dato('precio_lista');

        return new self(
            productoId: (int) $dato('producto_id'),
            nombre: (string) $dato('nombre'),
            precioUnitario: (float) $dato('precio_unitario'),
            cantidad: (int) $dato('cantidad'),
            gravaIsv: (bool) $dato('grava_isv'),
            detalle: is_array($detalle) ? $detalle : [],
            precioListaUnitario: $lista === null ? null : (float) $lista,
            componentes: is_array($componentes)
                ? array_map(static fn (array $c): ComponenteLinea => ComponenteLinea::fromArray($c), $componentes)
                : [],
            nota: (string) ($dato('nota') ?? ''),
        );
    }

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

    /**
     * Igual que repartoNeto(), pero sobre el importe de LISTA (à la carte).
     *
     * Hace falta para la venta exonerada: al quitarle el ISV a la línea hay
     * que quitárselo también al precio de lista, o el renglón "Descuentos y
     * rebajas" del ticket deja de cuadrar con el total.
     *
     * @return array<int, array{neto: float, grava: bool}>
     */
    public function repartoLista(): array
    {
        $lista = $this->subtotalLista();

        if ($this->componentes === []) {
            return [['neto' => $lista, 'grava' => $this->gravaIsv]];
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
                $monto = round($lista - $acumulado, 2);   // el último cuadra el redondeo
            } else {
                $peso = $baseLista > 0.0 ? ($c->importeLista() / $baseLista) : (1 / $n);
                $monto = round($lista * $peso, 2);
                $acumulado += $monto;
            }

            $reparto[] = ['neto' => $monto, 'grava' => $c->gravaIsv];
        }

        return $reparto;
    }

    /**
     * Copia de la línea TARIFADA EN NETO: al importe gravado se le quita el
     * ISV, lo exento se queda igual.
     *
     * Es lo que se le cobra a un comprador exonerado. El precio de menú es
     * "ISV incluido" (Ley del ISV art. 7), o sea que un plato de L.100 vale
     * L.86.96 de producto + L.13.04 de impuesto; el exonerado no paga el
     * impuesto, así que paga L.86.96. No es un descuento comercial: es el
     * mismo precio sin el tributo que la OCE dispensa.
     *
     * Se re-tarifa LA LÍNEA y no solo los totales a propósito: así el detalle
     * impreso, el subtotal, el descuento y el total salen todos del mismo
     * número y cuadran solos. Si se tocaran nada más los totales, el ticket
     * mostraría platos de L.100 sumando L.86.96.
     *
     * Ojo con la cantidad: el precio unitario se redondea a 2 decimales, así
     * que el importe de la línea se recalcula desde ÉL y no desde el importe
     * teórico. Puede diferir un centavo del neto exacto, pero todo lo que
     * viene después sale de este mismo precio y por eso cuadra.
     */
    public function sinIsv(float $tasaIsv): self
    {
        $divisor = 1 + $tasaIsv;

        $neto = 0.0;

        foreach ($this->repartoNeto() as $parte) {
            $neto += $parte['grava'] ? round($parte['neto'] / $divisor, 2) : $parte['neto'];
        }

        $lista = 0.0;

        foreach ($this->repartoLista() as $parte) {
            $lista += $parte['grava'] ? round($parte['neto'] / $divisor, 2) : $parte['neto'];
        }

        $cantidad = max(1, $this->cantidad);

        return new self(
            productoId: $this->productoId,
            nombre: $this->nombre,
            precioUnitario: round($neto / $cantidad, 2),
            cantidad: $this->cantidad,
            gravaIsv: $this->gravaIsv,
            detalle: $this->detalle,
            // Sin precio de lista propio no hay descuento que mostrar; dejarlo
            // en null evita inventar uno por el redondeo del neto.
            precioListaUnitario: $this->precioListaUnitario === null ? null : round($lista / $cantidad, 2),
            componentes: array_map(
                static fn (ComponenteLinea $c): ComponenteLinea => $c->sinIsv($tasaIsv),
                $this->componentes,
            ),
            nota: $this->nota,
        );
    }
}
