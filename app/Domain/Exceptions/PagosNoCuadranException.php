<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Los pagos de una venta (efectivo/tarjeta/transferencia) no suman el
 * total exacto. Fail fast: nunca se persiste una venta con pagos que
 * no cuadran al centavo.
 */
final class PagosNoCuadranException extends RestauranteException
{
    public function __construct(float $sumaPagos, float $total)
    {
        parent::__construct(sprintf(
            'Los pagos suman L. %.2f pero el total de la venta es L. %.2f.',
            $sumaPagos,
            $total,
        ));
    }
}
