<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\ResumenVenta;

/**
 * Contrato del calculador de impuestos de una venta (Dependency
 * Inversion). Los services dependen de esta interfaz, no de la
 * implementación concreta; el binding vive en AppServiceProvider.
 */
interface CalculaImpuestos
{
    /**
     * @param iterable<LineaVenta> $lineas
     * @param bool $exonerada Venta amparada por una Orden de Compra Exenta:
     *                        lo que gravaría se declara como exonerado y no
     *                        genera ISV. Las líneas ya deben venir tarifadas
     *                        en neto (LineaVenta::sinIsv).
     */
    public function calcular(iterable $lineas, bool $exonerada = false): ResumenVenta;

    /**
     * Re-tarifa las líneas en NETO (sin ISV) para una venta exonerada.
     *
     * Vive acá y no en el service porque la tasa del ISV es del calculador:
     * nadie más tiene por qué conocerla.
     *
     * @param iterable<LineaVenta> $lineas
     *
     * @return array<int, LineaVenta>
     */
    public function tarifarSinIsv(iterable $lineas): array;
}
