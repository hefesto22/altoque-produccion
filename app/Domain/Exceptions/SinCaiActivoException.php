<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No hay ningún CAI activo y vigente para emitir factura SAR.
 *
 * Decisión de negocio confirmada: la caja NO se bloquea — sigue
 * vendiendo con recibo interno; solo se impide emitir factura fiscal
 * hasta cargar/activar un nuevo rango autorizado.
 */
final class SinCaiActivoException extends FacturacionException
{
    public function __construct()
    {
        parent::__construct(
            'No hay un CAI activo y vigente. No se puede emitir factura SAR; '
            .'la venta puede registrarse como recibo interno. Cargar un nuevo rango autorizado.'
        );
    }
}
