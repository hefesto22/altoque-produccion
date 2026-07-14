<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El evento no se puede facturar en este momento: cotización no
 * aceptada, ya facturada, saldo pendiente, sin turno abierto o
 * desglose que no cuadra al centavo. El motivo exacto viaja en el
 * mensaje, pensado para mostrarse tal cual en la notificación.
 */
final class EventoNoFacturableException extends RestauranteException
{
    public function __construct(string $motivo)
    {
        parent::__construct($motivo);
    }
}
