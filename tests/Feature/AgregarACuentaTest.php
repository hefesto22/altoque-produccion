<?php

declare(strict_types=1);

use App\Domain\Exceptions\CuentaNoAmpliableException;
use App\Domain\ValueObjects\LineaVenta;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Pos\VentaService;

/**
 * Cuenta abierta: el cliente ya pidió y a los diez minutos pide otra bebida.
 * Lo nuevo se le SUMA a la misma venta (una sola factura al final) y cocina
 * recibe un papel aparte con solo lo agregado.
 */

/** @return array<int, LineaVenta> Una línea con producto real (FK válida). */
function lineaCuenta(string $nombre, float $precio, bool $grava): array
{
    $p = Producto::factory()->proteina()->create(['nombre' => $nombre, 'precio' => $precio]);

    return [new LineaVenta($p->id, $nombre, $precio, 1, gravaIsv: $grava)];
}

it('suma lo agregado a la MISMA venta, al centavo y sin tocar el número de orden', function () {
    $cajero = User::factory()->create();

    $venta = app(VentaService::class)->registrarPendiente(
        lineaCuenta('Pollo', 100.00, grava: false),
        $cajero->id,
        'local',
    );

    expect((float) $venta->total)->toBe(100.00);

    $ampliada = app(VentaService::class)->agregarACuenta(
        $venta,
        lineaCuenta('Mirinda Banana', 25.00, grava: true),
        $cajero->id,
    );

    // Una sola venta: la cuenta del cliente no se parte en dos.
    expect(Venta::query()->count())->toBe(1)
        ->and($ampliada->items()->count())->toBe(2)
        ->and($ampliada->numero_orden)->toBe('LOC-1')   // no consume otro ticket diario
        ->and($ampliada->pagada)->toBeFalse()
        ->and($ampliada->corte_caja_id)->toBeNull()     // sigue sin entrar a ningún turno
        // Desglose sumado: 100 exento + 25 gravado (ISV incluido → 21.74 + 3.26).
        ->and((float) $ampliada->exento)->toBe(100.00)
        ->and((float) $ampliada->gravado)->toBe(21.74)
        ->and((float) $ampliada->isv)->toBe(3.26)
        ->and((float) $ampliada->total)->toBe(125.00);
});

it('al cobrar sale UNA sola factura por el total de la cuenta completa', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $corte = app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(
        lineaCuenta('Pollo', 100.00, grava: false),
        $cajero->id,
        'local',
    );

    app(VentaService::class)->agregarACuenta(
        $venta,
        lineaCuenta('Mirinda Banana', 25.00, grava: true),
        $cajero->id,
    );

    $factura = app(VentaService::class)->cobrarPendiente(
        $venta->refresh(),
        $cajero->id,
        null,
        'Consumidor Final',
        'efectivo',
    );

    $venta->refresh();

    expect(Factura::query()->count())->toBe(1)          // un solo correlativo SAR
        ->and($factura->venta_id)->toBe($venta->id)
        ->and((float) $venta->total)->toBe(125.00)
        ->and($venta->pagada)->toBeTrue();

    // El corte del turno recibe la cuenta completa, no solo lo primero.
    $cerrado = app(CorteCajaService::class)->cerrar($corte->fresh(), 125.00);

    expect((float) $cerrado->total_ventas)->toBe(125.00)
        ->and($cerrado->cantidad_ventas)->toBe(1);
});

it('no se le agrega nada a una cuenta ya cobrada', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(
        lineaCuenta('Pollo', 100.00, grava: false),
        $cajero->id,
        'local',
    );

    app(VentaService::class)->cobrarPendiente($venta, $cajero->id, null, 'Consumidor Final', 'efectivo');

    // Con factura emitida el total ya no se toca: lo nuevo va como pedido aparte.
    expect(fn () => app(VentaService::class)->agregarACuenta(
        $venta->refresh(),
        lineaCuenta('Coca Cola', 25.00, grava: true),
        $cajero->id,
    ))->toThrow(CuentaNoAmpliableException::class);

    expect((float) $venta->refresh()->total)->toBe(100.00);
});

it('no se le agrega nada a un pedido anulado', function () {
    $cajero = User::factory()->create();

    $venta = app(VentaService::class)->registrarPendiente(
        lineaCuenta('Pollo', 100.00, grava: false),
        $cajero->id,
        'local',
    );

    app(VentaService::class)->anularPendiente($venta, $cajero->id, 'el cliente se fue');

    expect(fn () => app(VentaService::class)->agregarACuenta(
        $venta->refresh(),
        lineaCuenta('Coca Cola', 25.00, grava: true),
        $cajero->id,
    ))->toThrow(CuentaNoAmpliableException::class);
});

it('la comanda de lo agregado lleva SOLO lo nuevo y sale marcada', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = app(VentaService::class)->registrarPendiente(
        lineaCuenta('Pollo', 100.00, grava: false),
        $cajero->id,
        'local',
    );

    $original = app(ComandaService::class)->crear(
        $venta,
        'local',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]],
        ['nombre' => 'MAURICIO'],
    );

    app(VentaService::class)->agregarACuenta(
        $venta,
        lineaCuenta('Mirinda Banana', 25.00, grava: true),
        $cajero->id,
    );

    $ampliacion = app(ComandaService::class)->crear(
        $venta,
        'local',
        [['nombre' => 'Mirinda Banana', 'cantidad' => 1, 'detalle' => []]],
        ['nombre' => 'MAURICIO'],
        esAmpliacion: true,
    );

    expect($ampliacion->es_ampliacion)->toBeTrue()
        ->and($original->es_ampliacion)->toBeFalse();

    // El ticket de cocina: marcado, con lo nuevo y SIN lo que ya se hizo.
    $this->get($ampliacion->urlTicket())
        ->assertOk()
        ->assertSee('AGREGADO A LA ORDEN')
        ->assertSee('LOC-1')                 // misma orden que el original
        ->assertSee('Mirinda Banana')
        ->assertDontSee('Pollo');

    // Al cobrar la cuenta, las dos comandas salen de la pantalla de cocina.
    app(VentaService::class)->cobrarPendiente($venta->refresh(), $cajero->id, null, 'Consumidor Final', 'efectivo');

    expect($original->fresh()->estado)->toBe('entregado')
        ->and($ampliacion->fresh()->estado)->toBe('entregado');
});
