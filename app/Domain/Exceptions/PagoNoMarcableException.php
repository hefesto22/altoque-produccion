<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * La cuota del sistema no se puede marcar (o desmarcar) en el estado en que
 * está: ya se había marcado, o nunca se marcó.
 */
final class PagoNoMarcableException extends RestauranteException
{
    public function __construct(string $razon)
    {
        parent::__construct("No se pudo cambiar el pago: {$razon}");
    }
}
