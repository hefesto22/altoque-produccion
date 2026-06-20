<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se puede anular la factura: el período ya fue declarado al SAR, o
 * pasó el día límite de anulación del mes.
 */
final class FacturaNoAnulableException extends FacturacionException
{
    public function __construct(string $razon)
    {
        parent::__construct("No se puede anular la factura: {$razon}");
    }
}
