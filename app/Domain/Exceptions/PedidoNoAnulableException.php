<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se puede anular el pedido pendiente: ya se cobró (para deshacerlo hay
 * que anular la factura) o ya estaba anulado.
 */
final class PedidoNoAnulableException extends RestauranteException
{
    public function __construct(string $razon)
    {
        parent::__construct("No se puede anular el pedido: {$razon}");
    }
}
