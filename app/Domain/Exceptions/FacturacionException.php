<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Rama fiscal del dominio: errores al emitir factura SAR (CAI,
 * correlativo, rango agotado/vencido).
 */
abstract class FacturacionException extends RestauranteException {}
