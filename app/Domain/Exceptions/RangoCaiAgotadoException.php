<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El rango de correlativos del CAI activo se agotó (correlativo_actual
 * alcanzó correlativo_hasta). Hay que cargar un nuevo rango.
 */
final class RangoCaiAgotadoException extends FacturacionException
{
    public function __construct(public readonly int $caiId)
    {
        parent::__construct(
            "El rango del CAI {$caiId} está agotado. Cargar un nuevo rango autorizado por el SAR."
        );
    }
}
