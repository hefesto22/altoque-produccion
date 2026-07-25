<?php

declare(strict_types=1);

use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Venta;
use App\Services\Fiscal\DeclaracionIsvService;

it('resta el crédito fiscal de las compras al ISV de ventas (neto a pagar)', function () {
    $cajero = User::factory()->create();
    $mp = now()->subMonthNoOverflow();

    // Venta: plato de L.200 gravado → ISV débito 26.09.
    Venta::create([
        'cajero_id'  => $cajero->id, 'tipo' => 'recibo',
        'gravado'    => 173.91, 'exento' => 0, 'isv' => 26.09, 'total' => 200,
        'vendida_at' => $mp->copy()->startOfMonth()->addDays(2),
    ]);

    // Compra con crédito fiscal de L.15.
    Compra::factory()->create([
        'fecha'   => $mp->copy()->startOfMonth()->addDays(3),
        'gravado' => 100, 'isv' => 15, 'total' => 115,
    ]);

    $r = app(DeclaracionIsvService::class)->calcular($mp->year, $mp->month);

    expect($r->isv)->toBe(26.09)            // débito
        ->and($r->creditoFiscal)->toBe(15.0) // crédito
        ->and($r->isvAPagar)->toBe(11.09);   // neto a pagar
});

it('NO resta crédito fiscal por una compra registrada como recibo', function () {
    $cajero = User::factory()->create();
    $mp = now()->subMonthNoOverflow();

    Venta::create([
        'cajero_id'  => $cajero->id, 'tipo' => 'recibo',
        'gravado'    => 173.91, 'exento' => 0, 'isv' => 26.09, 'total' => 200,
        'vendida_at' => $mp->copy()->startOfMonth()->addDays(2),
    ]);

    // Factura: sí acredita.
    Compra::factory()->create([
        'fecha'   => $mp->copy()->startOfMonth()->addDays(3),
        'gravado' => 100, 'isv' => 15, 'total' => 115,
    ]);

    // Recibo de compra: el ISV NO se puede acreditar ante el SAR.
    Compra::factory()->recibo()->create([
        'fecha' => $mp->copy()->startOfMonth()->addDays(4),
        'total' => 500,
    ]);

    $r = app(DeclaracionIsvService::class)->calcular($mp->year, $mp->month);

    expect($r->creditoFiscal)->toBe(15.0)   // solo la factura
        ->and($r->isvAPagar)->toBe(11.09);
});

it('guarda el recibo como exento y sin ISV, aunque le manden gravado', function () {
    $compra = Compra::factory()->create([
        'tipo_documento' => 'recibo',
        'gravado'        => 100,
        'isv'            => 15,
        'total'          => 500,
    ]);

    expect((float) $compra->isv)->toBe(0.0)
        ->and((float) $compra->gravado)->toBe(0.0)
        ->and((float) $compra->exento)->toBe(500.0);
});

it('guarda el proveedor en el catálogo para autocompletar la próxima compra', function () {
    Compra::factory()->create([
        'proveedor_nombre' => 'Distribuidora La Bendicion',
        'proveedor_rtn'    => '08019985012345',
    ]);

    expect(Proveedor::rtnDe('distribuidora la bendicion'))->toBe('08019985012345');

    // Un recibo posterior sin RTN no debe borrar el RTN ya conocido.
    Compra::factory()->recibo()->create(['proveedor_nombre' => 'DISTRIBUIDORA LA BENDICION']);

    expect(Proveedor::rtnDe('DISTRIBUIDORA LA BENDICION'))->toBe('08019985012345');
});

it('marca saldo a favor cuando el crédito supera el débito', function () {
    $cajero = User::factory()->create();
    $mp = now()->subMonthNoOverflow();

    Venta::create([
        'cajero_id'  => $cajero->id, 'tipo' => 'recibo',
        'gravado'    => 66.67, 'exento' => 0, 'isv' => 10, 'total' => 76.67,
        'vendida_at' => $mp->copy()->startOfMonth()->addDay(),
    ]);

    Compra::factory()->create([
        'fecha'   => $mp->copy()->startOfMonth()->addDays(2),
        'gravado' => 100, 'isv' => 15, 'total' => 115,
    ]);

    $r = app(DeclaracionIsvService::class)->calcular($mp->year, $mp->month);

    expect($r->isvAPagar)->toBe(-5.0); // 10 − 15 = saldo a favor
});
