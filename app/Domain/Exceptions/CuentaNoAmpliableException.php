<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se le puede agregar nada a esa cuenta: ya se cobró (y con factura
 * emitida el total no se toca) o el pedido está anulado.
 */
final class CuentaNoAmpliableException extends RestauranteException
{
    public function __construct(string $razon)
    {
        parent::__construct("No se puede agregar a esa cuenta: {$razon}");
    }
}
