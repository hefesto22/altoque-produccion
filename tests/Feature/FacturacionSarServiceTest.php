<?php

declare(strict_types=1);

use App\Domain\Exceptions\RangoCaiAgotadoException;
use App\Domain\Exceptions\SinCaiActivoException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Pos\VentaService;

/** Crea un producto real y devuelve una línea que lo referencia (FK válida). */
function lineasDemo(): array
{
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    return [new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false)];
}

it('el correlativo CAI nunca se duplica entre emisiones', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $service = app(VentaService::class);
    $rtn = new RTN('08011985012345');

    $f1 = $service->registrarFactura(lineasDemo(), $cajero->id, $rtn, 'Cliente A');
    $f2 = $service->registrarFactura(lineasDemo(), $cajero->id, $rtn, 'Cliente B');

    expect($f1->numero)->not->toBe($f2->numero)
        ->and($f2->correlativo)->toBe($f1->correlativo + 1)
        ->and(Factura::count())->toBe(2);
});

it('lanza SinCaiActivoException y no deja factura a medias cuando no hay CAI', function () {
    $cajero = User::factory()->create();
    $service = app(VentaService::class);

    expect(fn () => $service->registrarFactura(
        lineasDemo(),
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente'
    ))->toThrow(SinCaiActivoException::class);

    // Rollback completo: ni factura ni venta fiscal a medias.
    expect(Factura::count())->toBe(0)
        ->and(Venta::count())->toBe(0);
});

it('lanza RangoCaiAgotadoException cuando el rango ya se consumió', function () {
    Cai::factory()->casiAgotado()->create(['correlativo_actual' => 1]);
    $cajero = User::factory()->create();
    $service = app(VentaService::class);

    expect(fn () => $service->registrarFactura(
        lineasDemo(),
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente'
    ))->toThrow(RangoCaiAgotadoException::class);
});

it('emite factura a Consumidor Final sin RTN', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();

    $factura = app(VentaService::class)->registrarFactura(lineasDemo(), $cajero->id, null, 'Consumidor Final');

    expect($factura->rtn_cliente)->toBeNull()
        // El nombre del cliente se normaliza a MAYÚSCULAS al facturar.
        ->and($factura->nombre_cliente)->toBe('CONSUMIDOR FINAL')
        ->and($factura->numero)->toStartWith('000-001-01-')
        ->and($factura->hash_verificacion)->not->toBeNull();
});

it('marca el CAI como agotado al consumir el último número', function () {
    Cai::factory()->casiAgotado()->create();
    $cajero = User::factory()->create();

    app(VentaService::class)->registrarFactura(
        lineasDemo(),
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente'
    );

    expect(Cai::first()->estado)->toBe('agotado');
});
