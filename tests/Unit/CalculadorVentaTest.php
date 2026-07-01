<?php

declare(strict_types=1);

use App\Domain\ValueObjects\ComponenteLinea;
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

it('desglosa el descuento de combo y calcula el ISV sobre el neto cobrado', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    // Pollo 60 + 3 complementos 30 = 150 à la carte; combo cobrado 125.
    $plato = new LineaVenta(
        productoId: 1,
        nombre: 'Pollo + 3 complementos',
        precioUnitario: 125.00,   // combo cobrado
        cantidad: 1,
        gravaIsv: true,
        precioListaUnitario: 150.00,
        componentes: [
            new ComponenteLinea('Pollo', 60.00, gravaIsv: true),
            new ComponenteLinea('Arroz', 30.00, gravaIsv: true),
            new ComponenteLinea('Ensalada', 30.00, gravaIsv: true),
            new ComponenteLinea('Frijoles', 30.00, gravaIsv: true),
        ],
    );

    $resumen = $calc->calcular([$plato]);

    expect($resumen->subtotalLista)->toBe(150.00)
        ->and($resumen->descuento)->toBe(25.00)        // 150 - 125
        ->and($resumen->gravado)->toBe(108.70)         // 125 / 1.15 (ISV sobre el NETO, no sobre 150)
        ->and($resumen->isv)->toBe(16.30)
        ->and($resumen->exento)->toBe(0.0)
        ->and($resumen->total)->toBe(125.00);
});

it('prorratea el descuento entre base gravada y exenta cuando el plato es mixto', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    // Proteína gravada 60 + complemento exento 90 = 150; combo cobrado 125.
    $plato = new LineaVenta(
        productoId: 1,
        nombre: 'Plato mixto',
        precioUnitario: 125.00,
        cantidad: 1,
        gravaIsv: true,
        precioListaUnitario: 150.00,
        componentes: [
            new ComponenteLinea('Proteína', 60.00, gravaIsv: true),
            new ComponenteLinea('Complementos', 90.00, gravaIsv: false),
        ],
    );

    $resumen = $calc->calcular([$plato]);

    // Neto 125 repartido por peso de lista: 60/150=40% grava, 90/150=60% exento.
    // Gravado neto = 50 → base 50/1.15 = 43.48, ISV 6.52. Exento = 75.
    expect($resumen->descuento)->toBe(25.00)
        ->and($resumen->gravado)->toBe(43.48)
        ->and($resumen->isv)->toBe(6.52)
        ->and($resumen->exento)->toBe(75.00)
        ->and($resumen->total)->toBe(125.00);            // cuadra al centavo
});

it('mantiene el invariante subtotalLista = total + descuento', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    $resumen = $calc->calcular([
        new LineaVenta(
            productoId: 1,
            nombre: 'Combo',
            precioUnitario: 110.00,
            cantidad: 2,
            gravaIsv: true,
            precioListaUnitario: 130.00,
            componentes: [
                new ComponenteLinea('Res', 70.00, gravaIsv: true),
                new ComponenteLinea('Comp', 60.00, gravaIsv: true),
            ],
        ),
        new LineaVenta(2, 'Fresco', 25.00, 1, gravaIsv: true), // suelto, sin descuento
    ]);

    expect(round($resumen->subtotalLista, 2))->toBe(round($resumen->total + $resumen->descuento, 2))
        ->and($resumen->descuento)->toBe(40.00);   // (130-110) × 2 platos
});

it('no inventa descuento en una línea suelta sin componentes', function () {
    $resumen = (new CalculadorVenta(tasaIsv: 0.15))->calcular([
        new LineaVenta(2, 'Fresco', 25.00, 2, gravaIsv: true),
    ]);

    expect($resumen->descuento)->toBe(0.0)
        ->and($resumen->subtotalLista)->toBe(50.00)
        ->and($resumen->total)->toBe(50.00);
});
