<?php

declare(strict_types=1);

use App\Domain\Exceptions\PagosNoCuadranException;
use App\Domain\ValueObjects\LineaVenta;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Pos\VentaService;

it('registra una venta simple con un solo pago equivalente al total', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    $venta = app(VentaService::class)->registrarRecibo([
        new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false),
    ], $cajero->id, 'efectivo');

    expect($venta->forma_pago)->toBe('efectivo')
        ->and($venta->pagos)->toHaveCount(1)
        ->and((float) $venta->pagos->first()->monto)->toBe(120.00)
        ->and($venta->pagos->first()->metodo)->toBe('efectivo');
});

it('registra una venta mixta con sus pagos y forma_pago = mixto', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 500.00]);

    $venta = app(VentaService::class)->registrarRecibo(
        [new LineaVenta($pollo->id, 'Pollo', 500.00, 1, gravaIsv: false)],
        $cajero->id,
        'mixto',
        null,
        'local',
        pagos: [
            ['metodo' => 'efectivo', 'monto' => 300.00],
            ['metodo' => 'tarjeta', 'banco' => 'Banpaís', 'monto' => 200.00],
        ],
    );

    expect($venta->forma_pago)->toBe('mixto')
        ->and($venta->pagos)->toHaveCount(2)
        ->and((float) $venta->pagos->sum('monto'))->toBe((float) $venta->total);
});

it('rechaza un pago mixto que no cuadra con el total (fail fast)', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 500.00]);

    expect(fn () => app(VentaService::class)->registrarRecibo(
        [new LineaVenta($pollo->id, 'Pollo', 500.00, 1, gravaIsv: false)],
        $cajero->id,
        'mixto',
        null,
        'local',
        pagos: [
            ['metodo' => 'efectivo', 'monto' => 300.00],
            ['metodo' => 'tarjeta', 'monto' => 150.00], // faltan 50
        ],
    ))->toThrow(PagosNoCuadranException::class);

    // Rollback completo: no queda venta a medias.
    expect(Venta::count())->toBe(0);
});

it('colapsa a método único si solo un método trae monto', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 200.00]);

    $venta = app(VentaService::class)->registrarRecibo(
        [new LineaVenta($pollo->id, 'Pollo', 200.00, 1, gravaIsv: false)],
        $cajero->id,
        'mixto',
        null,
        'local',
        pagos: [
            ['metodo' => 'efectivo', 'monto' => 200.00],
            ['metodo' => 'tarjeta', 'monto' => 0],
            ['metodo' => 'transferencia', 'monto' => 0],
        ],
    );

    expect($venta->forma_pago)->toBe('efectivo')
        ->and($venta->pagos)->toHaveCount(1);
});

it('el corte de caja espera en gaveta SOLO la porción en efectivo del mixto', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 500.00]);

    $corte = app(CorteCajaService::class)->abrir($cajero->id, fondoInicial: 100.00);

    app(VentaService::class)->registrarRecibo(
        [new LineaVenta($pollo->id, 'Pollo', 500.00, 1, gravaIsv: false)],
        $cajero->id,
        'mixto',
        null,
        'local',
        pagos: [
            ['metodo' => 'efectivo', 'monto' => 300.00],
            ['metodo' => 'transferencia', 'banco' => 'Ficohsa', 'monto' => 200.00],
        ],
    );

    $cerrado = app(CorteCajaService::class)->cerrar($corte, efectivoContado: 400.00);

    expect((float) $cerrado->total_efectivo)->toBe(300.00)          // no los 500
        ->and((float) $cerrado->total_transferencia)->toBe(200.00)
        ->and((float) $cerrado->total_ventas)->toBe(500.00)
        ->and((float) $cerrado->diferencia)->toBe(0.00);            // 100 fondo + 300 efectivo = 400 contados
});

it('guarda el nombre de la orden en mayúsculas como snapshot', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    $venta = app(VentaService::class)->registrarRecibo(
        [new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false)],
        $cajero->id,
        'efectivo',
        null,
        'llevar',
        nombreOrden: 'juan pérez',
    );

    expect($venta->nombre_orden)->toBe('JUAN PÉREZ');
});
