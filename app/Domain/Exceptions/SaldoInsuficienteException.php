<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * La cuenta prepago no alcanza para cubrir el consumo (y no tiene crédito
 * habilitado, o el consumo se pasa de su tope).
 */
final class SaldoInsuficienteException extends RestauranteException
{
    public function __construct(float $disponible, float $requerido)
    {
        parent::__construct(sprintf(
            'Saldo insuficiente: disponible L. %s y se necesitan L. %s.',
            number_format($disponible, 2),
            number_format($requerido, 2),
        ));
    }
}
