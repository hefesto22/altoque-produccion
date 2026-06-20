<?php

declare(strict_types=1);

use App\Models\Comanda;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Pedidos\PedidoOnlineService;

function itemsPedido(int $productoId): array
{
    return [[
        'producto_id' => $productoId,
        'nombre'      => 'Pollo + 2 complementos',
        'precio'      => 100.00,
        'cantidad'    => 1,
        'grava_isv'   => false,
        'detalle'     => ['Arroz', 'Ensalada'],
    ]];
}

it('crea un pedido online pendiente con su correlativo', function () {
    $producto = Producto::factory()->proteina()->create();

    $pedido = app(PedidoOnlineService::class)->crear(
        ['tipo' => 'domicilio', 'nombre' => 'Ana', 'telefono' => '9999', 'direccion' => 'Centro', 'metodo_pago' => 'efectivo'],
        itemsPedido($producto->id),
        100.00,
    );

    expect($pedido->estado)->toBe('pendiente')
        ->and($pedido->numero)->toStartWith('P-')
        ->and($pedido->tipo)->toBe('domicilio')
        ->and((float) $pedido->total)->toBe(100.00);
});

it('al confirmar genera la venta (recibo) y la comanda', function () {
    $producto = Producto::factory()->proteina()->create();
    $cajero = User::factory()->create();

    $pedido = app(PedidoOnlineService::class)->crear(
        ['tipo' => 'domicilio', 'nombre' => 'Ana', 'telefono' => '9999', 'direccion' => 'Centro', 'metodo_pago' => 'efectivo'],
        itemsPedido($producto->id),
        100.00,
    );

    app(PedidoOnlineService::class)->confirmar($pedido, $cajero->id);

    $pedido->refresh();

    expect($pedido->estado)->toBe('confirmado')
        ->and($pedido->venta_id)->not->toBeNull()
        ->and(Venta::count())->toBe(1)
        ->and(Comanda::where('venta_id', $pedido->venta_id)->where('tipo', 'domicilio')->exists())->toBeTrue();
});

it('rechaza un pedido con motivo y no crea venta', function () {
    $producto = Producto::factory()->proteina()->create();

    $pedido = app(PedidoOnlineService::class)->crear(
        ['tipo' => 'retiro', 'nombre' => 'Luis', 'telefono' => '8888', 'metodo_pago' => 'efectivo'],
        itemsPedido($producto->id),
        100.00,
    );

    app(PedidoOnlineService::class)->rechazar($pedido, 'Fuera de horario');

    expect($pedido->fresh()->estado)->toBe('rechazado')
        ->and($pedido->fresh()->motivo_rechazo)->toBe('Fuera de horario')
        ->and(Venta::count())->toBe(0);
});
