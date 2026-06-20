<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Services\Pos\CalculadorVenta;

it('grava la bebida y deja exento el plato (modelo ISV incluido)', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    $resumen = $calc->calcular([
        new LineaVenta(1, 'Pollo', 120.00, 1, gravaIsv: false),
        new LineaVenta(2, 'Fresco', 23.00, 1, gravaIsv: true),
    ]);

    expect($resumen->exento)->toBe(120.00)
        ->and($resumen->gravado)->toBe(20.00)   // 23 / 1.15
        ->and($resumen->isv)->toBe(3.00)         // 23 - 20
        ->and($resumen->total)->toBe(143.00);
});

it('no cambia el total: el ISV está incluido en el precio', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    $resumen = $calc->calcular([
        new LineaVenta(2, 'Fresco', 25.00, 2, gravaIsv: true),
    ]);

    // 2 frescos a 25 = 50 total; el cliente paga 50, no 57.50.
    expect($resumen->total)->toBe(50.00)
        ->and($resumen->gravado + $resumen->isv)->toBe(50.00);
});

it('devuelve cero en una venta sin líneas', function () {
    $resumen = (new CalculadorVenta(tasaIsv: 0.15))->calcular([]);

    expect($resumen->total)->toBe(0.0)
        ->and($resumen->isv)->toBe(0.0);
});

it('respeta una tasa de ISV distinta sin tocar la fórmula', function () {
    // 18% (alcohol/tabaco) — la tasa entra por config, no hardcodeada.
    $resumen = (new CalculadorVenta(tasaIsv: 0.18))->calcular([
        new LineaVenta(9, 'Cerveza', 59.00, 1, gravaIsv: true),
    ]);

    expect($resumen->gravado)->toBe(50.00)  // 59 / 1.18
        ->and($resumen->isv)->toBe(9.00);
});
