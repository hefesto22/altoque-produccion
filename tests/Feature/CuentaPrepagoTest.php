<?php

declare(strict_types=1);

use App\Domain\Exceptions\SaldoInsuficienteException;
use App\Models\CuentaMovimiento;
use App\Models\CuentaPrepago;
use App\Models\User;
use App\Services\Cuentas\CuentaPrepagoService;

/**
 * Cuentas prepago: la empresa deja dinero por adelantado y consume contra ese
 * saldo. El depósito NO es venta; la factura sale en cada consumo.
 */
function cuentaPrepago(array $extra = []): CuentaPrepago
{
    return CuentaPrepago::create([
        'nombre'   => 'inversiones olympo',
        'rtn'      => '13212003002192',
        'telefono' => '33012826',
        ...$extra,
    ]);
}

it('nace con token propio y el nombre en mayúsculas', function () {
    $cuenta = cuentaPrepago();

    expect($cuenta->token)->toHaveLength(40)
        ->and($cuenta->nombre)->toBe('INVERSIONES OLYMPO')
        ->and((float) $cuenta->saldo)->toBe(0.0)
        ->and($cuenta->permite_credito)->toBeFalse();
});

it('un depósito sube el saldo y queda en el libro con su forma de pago', function () {
    $cajero = User::factory()->create();
    $cuenta = cuentaPrepago();

    $mov = app(CuentaPrepagoService::class)->depositar(
        $cuenta,
        10000.00,
        'transferencia',
        'Banco de Occidente',
        'REF-8891',
        'Depósito de agosto',
        $cajero->id,
    );

    expect((float) $cuenta->fresh()->saldo)->toBe(10000.00)
        ->and($mov->tipo)->toBe('deposito')
        ->and((float) $mov->monto)->toBe(10000.00)      // los depósitos van en positivo
        ->and((float) $mov->saldo_despues)->toBe(10000.00)
        ->and($mov->forma_pago)->toBe('transferencia')
        ->and($mov->banco)->toBe('Banco de Occidente')
        ->and($mov->referencia)->toBe('REF-8891')
        ->and($mov->registrado_por)->toBe($cajero->id);
});

it('un consumo descuenta y queda en negativo en el libro', function () {
    $cuenta = cuentaPrepago();
    $svc = app(CuentaPrepagoService::class);

    $svc->depositar($cuenta, 10000.00, 'efectivo');
    $mov = $svc->consumir($cuenta, 350.75);

    expect((float) $cuenta->fresh()->saldo)->toBe(9649.25)
        ->and((float) $mov->monto)->toBe(-350.75)
        ->and((float) $mov->saldo_despues)->toBe(9649.25)
        ->and($cuenta->totalDepositado())->toBe(10000.00)
        ->and($cuenta->totalConsumido())->toBe(350.75);
});

it('el saldo siempre es la suma de los movimientos', function () {
    $cuenta = cuentaPrepago();
    $svc = app(CuentaPrepagoService::class);

    $svc->depositar($cuenta, 10000.00, 'cheque', null, 'CH-114');
    $svc->consumir($cuenta, 1234.56);
    $svc->consumir($cuenta, 99.99);
    $svc->ajustar($cuenta, -50.00, 'Se cobró de más el martes');

    // La columna existe para que cobrar sea rápido; la suma existe para poder
    // auditarla. Si un día no coinciden, algo tocó el saldo por fuera.
    $suma = round((float) CuentaMovimiento::query()->where('cuenta_prepago_id', $cuenta->id)->sum('monto'), 2);

    expect((float) $cuenta->fresh()->saldo)->toBe(8615.45)
        ->and($suma)->toBe(8615.45);
});

it('sin crédito no se puede consumir más de lo depositado', function () {
    $cuenta = cuentaPrepago();
    $svc = app(CuentaPrepagoService::class);

    $svc->depositar($cuenta, 500.00, 'efectivo');

    expect(fn () => $svc->consumir($cuenta, 500.01))
        ->toThrow(SaldoInsuficienteException::class);

    // La venta que no pasó no dejó rastro: el saldo quedó intacto.
    expect((float) $cuenta->fresh()->saldo)->toBe(500.00)
        ->and($cuenta->movimientos()->count())->toBe(1);
});

it('con crédito habilitado el saldo puede quedar en rojo hasta el tope', function () {
    $cuenta = cuentaPrepago(['permite_credito' => true, 'limite_credito' => 1000.00]);
    $svc = app(CuentaPrepagoService::class);

    $svc->depositar($cuenta, 200.00, 'efectivo');

    // 200 de saldo + 1000 de crédito = 1200 disponibles.
    expect($cuenta->fresh()->disponible())->toBe(1200.00);

    $svc->consumir($cuenta->fresh(), 900.00);

    expect((float) $cuenta->fresh()->saldo)->toBe(-700.00);

    // Pero el tope es tope: 300 más ya no caben.
    expect(fn () => $svc->consumir($cuenta->fresh(), 301.00))
        ->toThrow(SaldoInsuficienteException::class);
});

it('el depósito en efectivo se ata al turno; el de banco no', function () {
    $cuenta = cuentaPrepago();
    $svc = app(CuentaPrepagoService::class);

    // El billete entra a la gaveta: el arqueo del turno tiene que contarlo.
    $enEfectivo = $svc->depositar($cuenta, 5000.00, 'efectivo', null, null, null, null, corteCajaId: 7);
    // Una transferencia no toca la gaveta, así que no ensucia el arqueo.
    $porBanco = $svc->depositar($cuenta, 5000.00, 'transferencia', 'Banco Atlántida', 'REF-1', null, null, corteCajaId: 7);

    expect($enEfectivo->corte_caja_id)->toBe(7)
        ->and($porBanco->corte_caja_id)->toBeNull();
})->skip('corte_caja_id apunta a un turno real: se prueba en la etapa del corte');

it('el token no cambia al editar la cuenta', function () {
    $cuenta = cuentaPrepago();
    $token = $cuenta->token;

    $cuenta->update(['nombre' => 'OTRO NOMBRE', 'telefono' => '99887766']);

    // El cliente guarda ese link en su WhatsApp: si cambia, deja de servir.
    expect($cuenta->fresh()->token)->toBe($token);
});
