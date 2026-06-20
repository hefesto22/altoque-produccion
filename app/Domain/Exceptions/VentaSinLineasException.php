<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó registrar una venta sin líneas. Fail fast: nunca se
 * persiste una venta vacía.
 */
final class VentaSinLineasException extends RestauranteException
{
    public function __construct()
    {
        parent::__construct('No se puede registrar una venta sin líneas.');
    }
}
