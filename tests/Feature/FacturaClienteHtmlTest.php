<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Services\Pos\VentaService;

/**
 * La factura que se le manda al cliente es HTML, no PDF (2026-07-30).
 *
 * Por qué: el PDF levanta un Chromium en el servidor (~3 s, y 500 si dos
 * personas abren el link a la vez), y cachearlo obligaría a guardar un archivo
 * por venta — con 1.000 facturas al mes eso crece para siempre. El HTML sale
 * al instante y no deja nada en disco; el PDF queda a un toque, bajo pedido.
 */
function facturaParaCliente(): Factura
{
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    return app(VentaService::class)->registrarFactura(
        [new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false)],
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente Prueba',
    );
}

it('el link de WhatsApp apunta al HTML de la factura, no al PDF', function () {
    $factura = facturaParaCliente();

    expect($factura->urlWhatsApp())
        ->toContain(rawurlencode('cliente=1'))
        ->and($factura->urlWhatsApp())->not->toContain(rawurlencode('/pdf'));
});

it('la vista del cliente abre sin login y ofrece descargar el PDF', function () {
    $factura = facturaParaCliente();

    $this->get($factura->urlCliente())
        ->assertOk()
        ->assertSee('Descargar PDF')
        ->assertSee($factura->numero);
});

it('la impresión de caja NO lleva el botón de descarga', function () {
    $factura = facturaParaCliente();

    $this->get($factura->urlTicket())
        ->assertOk()
        ->assertDontSee('Descargar PDF');
});

it('sin firma válida la factura del cliente no se abre', function () {
    $factura = facturaParaCliente();

    $this->get(route('facturas.ticket', ['factura' => $factura->id, 'cliente' => 1]))
        ->assertForbidden();
});
