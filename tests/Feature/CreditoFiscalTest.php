<?php

declare(strict_types=1);

use App\Models\Compra;
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
