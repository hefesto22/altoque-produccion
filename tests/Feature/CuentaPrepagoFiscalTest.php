<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\CuentaPrepago;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Facturacion\FacturacionSarService;
use App\Services\Fiscal\DeclaracionIsvService;
use App\Services\Pos\VentaService;

/**
 * Modelo fiscal de las cuentas prepago (2026-08-17).
 *
 * La regla de la que cuelga todo lo demás:
 *
 *   EL DEPÓSITO ES LA VENTA. Se factura una sola vez, al recibir el dinero.
 *   EL CONSUMO NO ES VENTA. Solo descuenta; no factura ni declara ISV.
 *
 * Si un consumo se colara en la declaración o en el corte, el negocio pagaría
 * ISV dos veces por el mismo lempira: una al depositar y otra al comer. Por
 * eso acá hay pruebas de la declaración y del corte, no solo del saldo.
 */
function cuentaFiscal(array $extra = []): CuentaPrepago
{
    $cuenta = CuentaPrepago::create([
        'nombre'     => 'inversiones olympo',
        'cliente_id' => Cliente::registrar('13212003002192', 'inversiones olympo')->id,
        ...$extra,
    ]);

    return $cuenta->fresh();
}

/** @return array<int, LineaVenta> */
function lineaFiscal(string $nombre, float $precio, bool $grava): array
{
    $p = Producto::factory()->proteina()->create(['nombre' => $nombre, 'precio' => $precio, 'grava_isv' => $grava]);

    return [new LineaVenta($p->id, $nombre, $precio, 1, gravaIsv: $grava)];
}

function cajeroConTurno(): User
{
    Cai::factory()->create();
    $cajero = User::factory()->create();
    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    return $cajero;
}

it('el deposito emite UNA factura por el monto, toda gravada', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();

    $factura = app(VentaService::class)->registrarDeposito(
        $cuenta,
        10000.00,
        $cajero->id,
        'transferencia',
        'Banco de Occidente',
        '9988',
    );

    $venta = $factura->venta;

    // ISV incluido en el precio: 10,000 / 1.15 = 8,695.65 + 1,304.35.
    expect($factura->numero)->not->toBeEmpty()
        ->and((float) $venta->total)->toBe(10000.00)
        ->and((float) $venta->gravado)->toBe(8695.65)
        ->and((float) $venta->isv)->toBe(1304.35)
        ->and((float) $venta->exento)->toBe(0.0)
        ->and($venta->tipo)->toBe('factura')
        ->and($venta->rtn_cliente)->toBe('13212003002192');

    expect((float) $cuenta->fresh()->saldo)->toBe(10000.00)
        ->and($cuenta->movimientos()->where('tipo', 'deposito')->value('venta_id'))->toBe($venta->id);
});

it('el consumo NO emite factura y solo descuenta', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();
    app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);

    $facturasAntes = Factura::query()->count();

    $venta = app(VentaService::class)->registrarConsumo(
        lineaFiscal('Pollo', 150.00, grava: false),
        $cajero->id,
        $cuenta->fresh(),
    );

    // Ni una factura nueva, ni un correlativo quemado.
    expect(Factura::query()->count())->toBe($facturasAntes)
        ->and($venta->tipo)->toBe('consumo')
        ->and($venta->factura)->toBeNull()
        ->and((float) $venta->total)->toBe(150.00)
        ->and((float) $cuenta->fresh()->saldo)->toBe(9850.00);
});

it('exento o gravado da igual: del saldo se resta el TOTAL', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();
    app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);

    app(VentaService::class)->registrarConsumo(lineaFiscal('Pollo', 150.00, grava: false), $cajero->id, $cuenta->fresh());
    app(VentaService::class)->registrarConsumo(lineaFiscal('Refresco', 50.00, grava: true), $cajero->id, $cuenta->fresh());

    expect((float) $cuenta->fresh()->saldo)->toBe(9800.00);
});

it('el consumo NO vuelve a declarar ISV: se declara solo el deposito', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();

    app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);
    app(VentaService::class)->registrarConsumo(lineaFiscal('Refresco', 500.00, grava: true), $cajero->id, $cuenta->fresh());

    $resumen = app(DeclaracionIsvService::class)->calcular((int) now()->year, (int) now()->month);

    // Solo el depósito. Si el consumo entrara, el ISV subiría y el negocio
    // pagaria dos veces por el mismo dinero.
    expect(round($resumen->total, 2))->toBe(10000.00)
        ->and(round($resumen->isv, 2))->toBe(1304.35);
});

it('el corte cuenta el deposito como venta y el consumo NO', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $corte = app(CorteCajaService::class)->abrir($cajero->id, 0.0);
    $cuenta = cuentaFiscal();

    // Depósito en efectivo: ese billete SÍ está en la gaveta hoy.
    app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id, 'efectivo');
    app(VentaService::class)->registrarConsumo(lineaFiscal('Pollo', 150.00, grava: false), $cajero->id, $cuenta->fresh());

    $cerrado = app(CorteCajaService::class)->cerrar($corte->fresh(), 10000.00);

    expect((float) $cerrado->total_ventas)->toBe(10000.00)   // el consumo no suma
        ->and((float) $cerrado->total_efectivo)->toBe(10000.00)
        ->and((float) $cerrado->total_saldo)->toBe(150.00)   // informativo
        ->and((float) $cerrado->diferencia)->toBe(0.0);
});

it('anular la factura del deposito le quita el saldo a la cuenta', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();

    $factura = app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);
    expect((float) $cuenta->fresh()->saldo)->toBe(10000.00);

    app(FacturacionSarService::class)->anular($factura, 'se deposito a la cuenta equivocada', $cajero->id);

    // El saldo deja de existir: ya no hay factura que lo respalde.
    expect((float) $cuenta->fresh()->saldo)->toBe(0.0);

    $ajuste = $cuenta->movimientos()->where('tipo', 'ajuste')->latest('id')->firstOrFail();
    expect((float) $ajuste->monto)->toBe(-10000.00)
        ->and($ajuste->notas)->toContain($factura->numero);
});

it('anular deja la cuenta en rojo si ya se habia consumido', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();

    $factura = app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);
    app(VentaService::class)->registrarConsumo(lineaFiscal('Pollo', 400.00, grava: false), $cajero->id, $cuenta->fresh());

    app(FacturacionSarService::class)->anular($factura, 'error de caja', $cajero->id);

    // Queda debiendo lo que ya se comio, aunque pase el tope de credito: el
    // saldo sin factura detras no puede quedarse ahi.
    expect((float) $cuenta->fresh()->saldo)->toBe(-400.00);
});

it('cobrar un pendiente con saldo lo convierte en consumo, sin factura', function () {
    $cajero = cajeroConTurno();
    $cuenta = cuentaFiscal();
    app(VentaService::class)->registrarDeposito($cuenta, 10000.00, $cajero->id);
    $facturasAntes = Factura::query()->count();

    // Piden, comen y pagan al final: el caso normal de una empresa.
    $pendiente = app(VentaService::class)->registrarPendiente(
        lineaFiscal('Pollo', 250.00, grava: false),
        $cajero->id,
        'local',
    );

    $consumo = app(VentaService::class)->cobrarPendienteConSaldo($pendiente, $cuenta->fresh(), $cajero->id);

    expect(Factura::query()->count())->toBe($facturasAntes)
        ->and($consumo->tipo)->toBe('consumo')
        ->and($consumo->pagada)->toBeTrue()
        ->and((float) $cuenta->fresh()->saldo)->toBe(9750.00)
        ->and(Venta::query()->consumosDeCuenta()->count())->toBe(1);
});

it('el producto de abono no se cuela en el menu del POS', function () {
    $abono = Producto::abonoACuenta();

    expect($abono->es_sistema)->toBeTrue()
        ->and(Producto::query()->vendibles()->whereKey($abono->id)->exists())->toBeFalse();
});
