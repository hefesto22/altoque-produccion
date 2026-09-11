<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Componente de un plato (proteína o complemento) con su PRECIO DE LISTA
 * (à la carte) y su flag de ISV, congelados al momento de la venta.
 *
 * Es la pieza que permite:
 *   1. Calcular el descuento del combo: Σ precioLista − precio cobrado.
 *   2. Prorratear ese descuento entre base gravada y exenta (cada
 *      componente grava según SU propio flag — lo correcto ante el SAR).
 *   3. Detallar la factura producto por producto a precio de lista.
 *
 * Snapshot inmutable: si mañana cambia el precio del pollo, esta venta
 * histórica no se altera.
 */
final readonly class ComponenteLinea
{
    public function __construct(
        public string $nombre,
        public float $precio,      // precio de lista (à la carte) unitario
        public bool $gravaIsv,
        public int $cantidad = 1,
    ) {}

    /** Importe de lista del componente (precio × cantidad), a 2 decimales. */
    public function importeLista(): float
    {
        return round($this->precio * $this->cantidad, 2);
    }

    /**
     * Copia del componente a precio NETO (sin ISV) cuando grava.
     *
     * El detalle de una factura exonerada se imprime producto por producto:
     * si los componentes siguieran a precio con impuesto, las líneas no
     * sumarían el total exonerado.
     */
    public function sinIsv(float $tasaIsv): self
    {
        if (! $this->gravaIsv) {
            return $this;
        }

        return new self(
            nombre: $this->nombre,
            precio: round($this->precio / (1 + $tasaIsv), 2),
            gravaIsv: $this->gravaIsv,
            cantidad: $this->cantidad,
        );
    }

    /** @return array{nombre: string, precio: float, grava_isv: bool, cantidad: int} */
    public function toArray(): array
    {
        return [
            'nombre'    => $this->nombre,
            'precio'    => $this->precio,
            'grava_isv' => $this->gravaIsv,
            'cantidad'  => $this->cantidad,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            nombre: (string) ($data['nombre'] ?? ''),
            precio: (float) ($data['precio'] ?? 0),
            gravaIsv: (bool) ($data['grava_isv'] ?? false),
            cantidad: (int) ($data['cantidad'] ?? 1),
        );
    }
}
