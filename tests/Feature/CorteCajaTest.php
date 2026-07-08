<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\CorteCaja;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Facturacion\FacturacionSarService;
use App\Services\Pos\VentaService;

it('abre un turno y vincula las ventas a ese corte', function () {
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['precio' => 120]);

    $corte = app(CorteCajaService::class)->abrir($cajero->id, 500.00);

    $venta = app(VentaService::class)->registrarRecibo(
        [new LineaVenta($producto->id, 'Pollo', 120.00, 1, gravaIsv: false)],
        $cajero->id,
        'efectivo',
    );

    expect($venta->corte_caja_id)->toBe($corte->id)
        ->and($venta->forma_pago)->toBe('efectivo');
});

it('no abre dos turnos a la vez para el mismo cajero', function () {
    $cajero = User::factory()->create();
    $svc = app(CorteCajaService::class);

    $c1 = $svc->abrir($cajero->id, 500.00);
    $c2 = $svc->abrir($cajero->id, 999.00);

    expect($c2->id)->toBe($c1->id)
        ->and(CorteCaja::where('cajero_id', $cajero->id)->where('estado', 'abierto')->count())->toBe(1);
});

it('cierra el turno y concilia el efectivo (faltante/sobrante)', function () {
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['precio' => 100]);
    $ventas = app(VentaService::class);
    $caja = app(CorteCajaService::class);

    $corte = $caja->abrir($cajero->id, 200.00, fondoTerminal: 50.00);

    // Una venta en efectivo (100) y otra en tarjeta (100).
    $ventas->registrarRecibo([new LineaVenta($producto->id, 'A', 100.00, 1, gravaIsv: false)], $cajero->id, 'efectivo');
    $ventas->registrarRecibo([new LineaVenta($producto->id, 'B', 100.00, 1, gravaIsv: false)], $cajero->id, 'tarjeta');

    // Esperado en efectivo = fondo 200 + efectivo 100 = 300. Contamos 310 → sobrante 10.
    $cerrado = $caja->cerrar($corte, 310.00);

    expect($cerrado->estado)->toBe('cerrado')
        ->and((float) $cerrado->total_ventas)->toBe(200.00)
        ->and((float) $cerrado->total_efectivo)->toBe(100.00)
        ->and((float) $cerrado->total_tarjeta)->toBe(100.00)
        ->and((float) $cerrado->diferencia)->toBe(10.00)
        // Nuevo saldo del terminal POS: 50 inicial + 100 tarjeta + 0 transferencia.
        ->and((float) $cerrado->terminal_final)->toBe(150.00);
});

it('al cerrar el turno se vacía la pantalla de cocina', function () {
    $cajero = User::factory()->create();
    $caja = app(CorteCajaService::class);
    $corte = $caja->abrir($cajero->id, 0.0);

    $venta = Venta::create([
        'cajero_id' => $cajero->id, 'tipo' => 'recibo', 'total' => 100, 'vendida_at' => now(),
    ]);
    $comanda = app(ComandaService::class)->crear(
        $venta,
        'llevar',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]],
    );

    $caja->cerrar($corte, 0.0);

    expect($comanda->fresh()->estado)->toBe('entregado'); // salió de cocina
});

it('el cierre automático deja el corte cerrado y pendiente de revisión', function () {
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['precio' => 100]);

    $corte = app(CorteCajaService::class)->abrir($cajero->id, 500.00, fondoTerminal: 100.00);
    app(VentaService::class)->registrarRecibo(
        [new LineaVenta($producto->id, 'A', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        'tarjeta',
    );

    $this->artisan('caja:cierre-automatico')->assertSuccessful();

    $corte->refresh();
    expect($corte->estado)->toBe('cerrado')
        ->and($corte->cierre_automatico)->toBeTrue()
        ->and($corte->efectivo_contado)->toBeNull()   // nadie contó la gaveta
        ->and($corte->diferencia)->toBeNull()          // no hay contra qué conciliar
        ->and((float) $corte->terminal_final)->toBe(200.00) // 100 inicial + 100 tarjeta
        ->and((float) $corte->total_ventas)->toBe(100.00);
});

it('el cierre manual NO queda marcado como automático', function () {
    $cajero = User::factory()->create();
    $caja = app(CorteCajaService::class);
    $corte = $caja->abrir($cajero->id, 0.0);

    $cerrado = $caja->cerrar($corte, 0.0);

    expect($cerrado->cierre_automatico)->toBeFalse()
        ->and((float) $cerrado->diferencia)->toBe(0.00);
});

it('una venta con factura anulada no cuenta en el corte ni en el efectivo esperado', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['precio' => 100]);
    $ventas = app(VentaService::class);
    $caja = app(CorteCajaService::class);

    $corte = $caja->abrir($cajero->id, 200.00);

    // Dos facturas en efectivo; se anula una (ej: "anular y corregir").
    $f1 = $ventas->registrarFactura(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente Uno',
        'efectivo',
    );
    $ventas->registrarFactura(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente Dos',
        'efectivo',
    );

    app(FacturacionSarService::class)
        ->anular($f1, 'Error de digitación', $cajero->id);

    $cerrado = $caja->cerrar($corte, 300.00); // fondo 200 + solo la venta viva 100

    expect($cerrado->cantidad_ventas)->toBe(1)
        ->and((float) $cerrado->total_ventas)->toBe(100.00)
        ->and((float) $cerrado->total_efectivo)->toBe(100.00)
        ->and((float) $cerrado->diferencia)->toBe(0.00); // la anulada NO infló el esperado
});

it('el scope cuentaEnCaja excluye anuladas y pendientes', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['precio' => 100]);
    $ventas = app(VentaService::class);
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $factura = $ventas->registrarFactura(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente',
        'efectivo',
    );
    $ventas->registrarPendiente([new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)], $cajero->id, 'local');

    expect(Venta::cuentaEnCaja()->count())->toBe(1); // la viva; el pendiente no

    app(FacturacionSarService::class)->anular($factura, 'Prueba', $cajero->id);

    expect(Venta::cuentaEnCaja()->count())->toBe(0); // anulada: fuera de caja
});
