<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Models\Cai;
use App\Models\Producto;
use App\Models\User;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Pos\VentaService;

/** Una línea simple referenciando un producto real (FK válida). */
function lineaPlato(): array
{
    $p = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);

    return [new LineaVenta($p->id, 'Pollo', 100.00, 1, gravaIsv: false)];
}

it('un pendiente EN EL LOCAL genera comanda con su ticket imprimible', function () {
    $cajero = User::factory()->create();

    // "Pagar después" también aplica en el local: la cocina prepara con
    // el ticket físico y se cobra al entregar.
    $venta = app(VentaService::class)->registrarPendiente(lineaPlato(), $cajero->id, 'local');

    $comanda = app(ComandaService::class)->crear(
        $venta,
        'local',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => [], 'nota' => 'sin cebolla']],
    );

    expect($comanda->tipo)->toBe('local')                  // el CHECK de comandas acepta 'local'
        ->and($venta->numero_orden)->toBe('LOC-1');

    // Ticket firmado: 200 con el contenido de cocina; sin firma se rechaza.
    $this->get($comanda->urlTicket())
        ->assertOk()
        ->assertSee('LOC-1')
        ->assertSee('EN EL LOCAL')
        ->assertSee('Pollo')
        ->assertSee('sin cebolla')
        ->assertSee('PENDIENTE DE PAGO');

    $this->get(route('comandas.ticket', ['comanda' => $comanda->id]))
        ->assertForbidden();
});

it('registra un pedido pendiente sin cobrar, sin turno ni factura', function () {
    $cajero = User::factory()->create();

    $venta = app(VentaService::class)->registrarPendiente(
        lineaPlato(),
        $cajero->id,
        'domicilio',
        costoViaje: 20.00,
    );

    expect($venta->pagada)->toBeFalse()
        ->and($venta->corte_caja_id)->toBeNull()       // todavía no entra a ningún turno
        ->and($venta->tipo_orden)->toBe('domicilio')
        ->and($venta->numero_orden)->toBe('DOM-1')
        ->and($venta->factura)->toBeNull()
        ->and((float) $venta->costo_viaje)->toBe(20.00)
        ->and((float) $venta->total)->toBe(100.00);     // el viaje NO entra al total fiscal
});

it('el pendiente no cuenta en el corte hasta que se cobra', function () {
    $cajero = User::factory()->create();
    $corte = app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(lineaPlato(), $cajero->id, 'llevar');

    // Cierre con el pendiente sin cobrar: no debe sumar nada.
    $cerrado = app(CorteCajaService::class)->cerrar($corte->fresh(), 0.0);
    expect((float) $cerrado->total_ventas)->toBe(0.0)
        ->and($cerrado->cantidad_ventas)->toBe(0);
});

it('al cobrar un pendiente se emite factura, se marca pagada y entra al turno actual', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $corte = app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(lineaPlato(), $cajero->id, 'llevar');

    $factura = app(VentaService::class)->cobrarPendiente(
        $venta,
        $cajero->id,
        null,
        'Consumidor Final',
        'efectivo',
    );

    $venta->refresh();

    expect($venta->pagada)->toBeTrue()
        ->and($venta->corte_caja_id)->toBe($corte->id)  // entra al turno donde se cobró
        ->and($venta->tipo)->toBe('factura')
        ->and($factura->venta_id)->toBe($venta->id);
});

it('al cobrar un pendiente su comanda sale de cocina (entregado)', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(lineaPlato(), $cajero->id, 'domicilio');

    // La comanda nace en "preparando" y está en la cola de cocina.
    $comanda = app(ComandaService::class)->crear(
        $venta,
        'domicilio',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]],
    );
    expect($comanda->estado)->toBe('preparando');

    app(VentaService::class)->cobrarPendiente($venta, $cajero->id, null, 'Consumidor Final', 'efectivo');

    expect($comanda->fresh()->estado)->toBe('entregado')
        ->and($comanda->fresh()->entregado_at)->not->toBeNull();
});
