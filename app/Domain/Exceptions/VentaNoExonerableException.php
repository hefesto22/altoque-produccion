<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó exonerar una venta que ya tiene factura emitida.
 *
 * Regla del negocio (Mauricio, 2026-09-11): la Orden de Compra Exenta solo
 * se aplica EN EL MOMENTO en que la venta se cobra y se imprime la factura.
 * Ni antes ni después. Una venta ya facturada está hecha y no se re-tarifa:
 * su correlativo SAR, su desglose y su papel ya existen, y cambiarlos por
 * detrás dejaría la factura impresa diciendo una cosa y la base otra.
 *
 * Si de verdad hubo un error, el camino es el que ya tiene el sistema:
 * anular esa factura y volver a facturar —lo que emite un documento NUEVO,
 * con su correlativo y su impresión—, con los límites fiscales de siempre
 * (período no declarado y dentro del día límite de anulación).
 */
final class VentaNoExonerableException extends RestauranteException
{
    public function __construct(string $numeroFactura)
    {
        parent::__construct(
            "Esa venta ya se facturó ({$numeroFactura}): la orden de compra solo se aplica al emitir la factura. "
            .'Si hay que corregirla, anulá la factura y volvé a facturar.'
        );
    }
}
