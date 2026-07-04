<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Models\CorteCaja;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
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
