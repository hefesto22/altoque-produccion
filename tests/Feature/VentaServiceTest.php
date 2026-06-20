<?php

declare(strict_types=1);

use App\Domain\Exceptions\VentaSinLineasException;
use App\Domain\ValueObjects\LineaVenta;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Pos\VentaService;

it('registra una venta sin RTN como recibo con su desglose de ISV', function () {
    $cajero = User::factory()->create();
    $jugo = Producto::factory()->bebida()->create(['nombre' => 'Jugo natural', 'precio' => 30.00]);

    $venta = app(VentaService::class)->registrarRecibo([
        new LineaVenta($jugo->id, $jugo->nombre, 30.00, 1, gravaIsv: true),
    ], $cajero->id);

    expect($venta->tipo)->toBe('recibo')
        ->and((float) $venta->isv)->toBeGreaterThan(0)   // el ISV se calcula igual
        ->and($venta->numero_recibo)->toStartWith('R-')
        ->and($venta->items)->toHaveCount(1);
});

it('congela el snapshot de la línea en el item de venta', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    $venta = app(VentaService::class)->registrarRecibo([
        new LineaVenta($pollo->id, 'Pollo', 120.00, 2, gravaIsv: false),
    ], $cajero->id);

    $item = $venta->items->first();

    expect($item->nombre)->toBe('Pollo')
        ->and((float) $item->precio_unitario)->toBe(120.00)
        ->and($item->cantidad)->toBe(2)
        ->and((float) $item->importe)->toBe(240.00);
});

it('da correlativos de recibo distintos a cada venta', function () {
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);
    $service = app(VentaService::class);
    $linea = [new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false)];

    $v1 = $service->registrarRecibo($linea, $cajero->id);
    $v2 = $service->registrarRecibo($linea, $cajero->id);

    expect($v1->numero_recibo)->not->toBe($v2->numero_recibo);
});

it('rechaza una venta sin líneas (fail fast)', function () {
    $cajero = User::factory()->create();

    expect(fn () => app(VentaService::class)->registrarRecibo([], $cajero->id))
        ->toThrow(VentaSinLineasException::class);

    expect(Venta::count())->toBe(0);
});
