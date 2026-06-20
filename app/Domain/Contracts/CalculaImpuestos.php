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
     */
    public function calcular(iterable $lineas): ResumenVenta;
}
