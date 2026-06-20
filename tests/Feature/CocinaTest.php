<?php

declare(strict_types=1);

use App\Models\AlertaReposicion;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Cocina\ComandaService;
use App\Services\Cocina\ReposicionService;

function ventaSimple(): Venta
{
    $cajero = User::factory()->create();

    return Venta::create([
        'cajero_id'  => $cajero->id,
        'tipo'       => 'recibo',
        'total'      => 100,
        'vendida_at' => now(),
    ]);
}

it('crea una comanda a domicilio con datos del cliente y su snapshot de items', function () {
    $comanda = app(ComandaService::class)->crear(
        ventaSimple(),
        'domicilio',
        [['nombre' => 'Pollo + 2 complementos', 'cantidad' => 1, 'detalle' => ['Arroz', 'Ensalada']]],
        ['nombre' => 'Ana', 'telefono' => '9999-9999', 'direccion' => 'Barrio Centro', 'identidad' => '0801-1990-12345'],
    );

    expect($comanda->estado)->toBe('pendiente')
        ->and($comanda->numero)->toStartWith('C-')
        ->and($comanda->tipo)->toBe('domicilio')
        ->and($comanda->cliente_nombre)->toBe('Ana')
        ->and($comanda->cliente_direccion)->toBe('Barrio Centro')
        ->and($comanda->items)->toHaveCount(1)
        ->and($comanda->items[0]['nombre'])->toBe('Pollo + 2 complementos');
});

it('marca la comanda como lista con su timestamp', function () {
    $comanda = app(ComandaService::class)->crear(
        ventaSimple(),
        'llevar',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]],
    );

    app(ComandaService::class)->marcarListo($comanda);

    expect($comanda->fresh()->estado)->toBe('listo')
        ->and($comanda->fresh()->listo_at)->not->toBeNull();
});

it('no duplica la alerta activa de un complemento', function () {
    $producto = Producto::factory()->create();
    $svc = app(ReposicionService::class);

    $a1 = $svc->alertar($producto->id);
    $a2 = $svc->alertar($producto->id);

    expect($a1->id)->toBe($a2->id)
        ->and(AlertaReposicion::where('estado', 'activa')->count())->toBe(1);
});

it('repone y limpia la alerta del complemento', function () {
    $producto = Producto::factory()->create();
    $svc = app(ReposicionService::class);

    $svc->alertar($producto->id);
    $svc->reponer($producto->id);

    expect($svc->productosConAlerta())->toBe([])
        ->and(AlertaReposicion::where('estado', 'activa')->count())->toBe(0);
});
