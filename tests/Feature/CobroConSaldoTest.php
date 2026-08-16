<?php

declare(strict_types=1);

use App\Domain\Exceptions\SaldoInsuficienteException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\CuentaPrepago;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Services\Caja\CorteCajaService;
use App\Services\Cuentas\CuentaPrepagoService;
use App\Services\Pos\VentaService;

/**
 * Cobrar contra la cuenta prepago del cliente.
 *
 * La venta es una venta normal: factura SAR, correlativo, ISV y libros
 * iguales. Lo único distinto es de dónde sale la plata.
 */
function cuentaConSaldo(float $monto, array $extra = []): CuentaPrepago
{
    $cuenta = CuentaPrepago::create([
        'nombre'     => 'inversiones olympo',
        'cliente_id' => Cliente::registrar('13212003002192', 'inversiones olympo')->id,
        ...$extra,
    ]);

    app(CuentaPrepagoService::class)->depositar($cuenta, $monto, 'transferencia', 'Banco de Occidente');

    return $cuenta->fresh();
}

/** @return array<int, LineaVenta> */
function lineaDeSaldo(float $precio = 100.00): array
{
    $p = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => $precio]);

    return [new LineaVenta($p->id, 'Pollo', $precio, 1, gravaIsv: true)];
}

it('la venta con saldo emite factura normal y descuenta de la cuenta', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $cuenta = cuentaConSaldo(10000.00);

    $factura = app(VentaService::class)->registrarFactura(
        lineaDeSaldo(100.00),
        $cajero->id,
        new RTN('13212003002192'),
        'INVERSIONES OLYMPO',
        'saldo',
        cuentaSaldo: $cuenta,
    );

    // Factura de verdad: correlativo SAR, ISV y total iguales a cualquier venta.
    expect($factura->numero)->not->toBeEmpty()
        ->and((float) $factura->venta->total)->toBe(100.00)
        ->and($factura->venta->forma_pago)->toBe('saldo');

    // Y el saldo bajó exactamente lo facturado.
    expect((float) $cuenta->fresh()->saldo)->toBe(9900.00);

    // El movimiento queda atado a la venta: descuento sin factura detrás es
    // un agujero por donde se va la plata del cliente.
    $mov = $cuenta->movimientos()->where('tipo', 'consumo')->firstOrFail();
    expect((float) $mov->monto)->toBe(-100.00)
        ->and($mov->venta_id)->toBe($factura->venta_id);
});

it('si el saldo no alcanza no se emite factura ni se quema correlativo', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $cuenta = cuentaConSaldo(50.00);

    expect(fn () => app(VentaService::class)->registrarFactura(
        lineaDeSaldo(100.00),
        $cajero->id,
        new RTN('13212003002192'),
        'INVERSIONES OLYMPO',
        'saldo',
        cuentaSaldo: $cuenta,
    ))->toThrow(SaldoInsuficienteException::class);

    // Nada quedó a medias: ni factura, ni venta, ni movimiento.
    expect(Factura::query()->count())->toBe(0)
        ->and((float) $cuenta->fresh()->saldo)->toBe(50.00)
        ->and($cuenta->movimientos()->where('tipo', 'consumo')->count())->toBe(0);
});

it('con crédito habilitado la venta pasa y el saldo queda en rojo', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $cuenta = cuentaConSaldo(50.00, ['permite_credito' => true, 'limite_credito' => 500.00]);

    app(VentaService::class)->registrarFactura(
        lineaDeSaldo(100.00),
        $cajero->id,
        new RTN('13212003002192'),
        'INVERSIONES OLYMPO',
        'saldo',
        cuentaSaldo: $cuenta,
    );

    expect((float) $cuenta->fresh()->saldo)->toBe(-50.00);
});

it('el corte NO cuenta el saldo como efectivo en gaveta', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $corte = app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    app(VentaService::class)->registrarFactura(
        lineaDeSaldo(100.00),
        $cajero->id,
        new RTN('13212003002192'),
        'INVERSIONES OLYMPO',
        'saldo',
        cuentaSaldo: cuentaConSaldo(10000.00),
    );

    // Se cierra contando CERO en la gaveta: el dinero entró el día del
    // depósito, no hoy. Si el saldo contara como efectivo, el arqueo saldría
    // con 100 de faltante todos los días.
    $cerrado = app(CorteCajaService::class)->cerrar($corte->fresh(), 0.0);

    expect((float) $cerrado->total_ventas)->toBe(100.00)
        ->and((float) $cerrado->total_efectivo)->toBe(0.0)
        ->and((float) $cerrado->total_saldo)->toBe(100.00)
        ->and((float) $cerrado->diferencia)->toBe(0.0);
});
