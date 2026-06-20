<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Factura;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al emitir una factura SAR. Los listeners (impresión,
 * auditoría, reportes) reaccionan desacoplados; el service no sabe qué
 * pasa después.
 */
final class FacturaEmitida
{
    use Dispatchable;

    public function __construct(public readonly Factura $factura) {}
}
