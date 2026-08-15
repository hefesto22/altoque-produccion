<?php

declare(strict_types=1);

use App\Models\Cotizacion;
use App\Models\User;

/**
 * Registrar un abono mueve el estado solo.
 *
 * Quien pone plata ya aceptó. Pedirle después a la caja que además cambie el
 * estado a mano es papeleo que nadie hace, y la lista termina llena de
 * cotizaciones "borrador" con abonos encima.
 */
function cotizacionConTotal(string $estado = 'borrador'): Cotizacion
{
    return Cotizacion::create([
        'cliente_nombre' => 'INVERSIONES OLYMPO',
        'estado'         => $estado,
        'total'          => 400.00,
    ]);
}

it('un abono pasa la cotización de borrador a aceptada', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal('borrador');

    $resultado = $cotizacion->registrarAbono(100.00, 'efectivo', null, null, $cajero->id);

    expect($resultado['estadoNuevo'])->toBe('aceptada')
        ->and($cotizacion->fresh()->estado)->toBe('aceptada')
        ->and($cotizacion->pagado())->toBe(100.00)
        ->and($cotizacion->saldo())->toBe(300.00);
});

it('una cotización ya enviada también se acepta al abonar', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal('enviada');

    $cotizacion->registrarAbono(50.00, 'transferencia', 'Banco de Occidente', 'anticipo', $cajero->id);

    expect($cotizacion->fresh()->estado)->toBe('aceptada');
});

it('un segundo abono no vuelve a mover el estado', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal('borrador');

    $cotizacion->registrarAbono(100.00, 'efectivo', null, null, $cajero->id);
    $segundo = $cotizacion->fresh()->registrarAbono(100.00, 'efectivo', null, null, $cajero->id);

    expect($segundo['estadoNuevo'])->toBeNull()
        ->and($cotizacion->fresh()->estado)->toBe('aceptada')
        ->and($cotizacion->fresh()->pagado())->toBe(200.00);
});

it('no resucita una cotización rechazada', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal('rechazada');

    // Abonar sobre una rechazada es raro: que lo decida una persona, no el sistema.
    $resultado = $cotizacion->registrarAbono(100.00, 'efectivo', null, null, $cajero->id);

    expect($resultado['estadoNuevo'])->toBeNull()
        ->and($cotizacion->fresh()->estado)->toBe('rechazada');
});

it('el abono guarda forma de pago, banco y quién lo recibió', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal();

    $pago = $cotizacion->registrarAbono(75.00, 'tarjeta', 'Banco Atlántida', 'con POS', $cajero->id)['pago'];

    expect((float) $pago->monto)->toBe(75.00)
        ->and($pago->forma_pago)->toBe('tarjeta')
        ->and($pago->banco)->toBe('Banco Atlántida')
        ->and($pago->recibido_por)->toBe($cajero->id)
        ->and($pago->recibido_at)->not->toBeNull();
});

it('en efectivo no guarda banco aunque se lo manden', function () {
    $cajero = User::factory()->create();
    $cotizacion = cotizacionConTotal();

    $pago = $cotizacion->registrarAbono(75.00, 'efectivo', 'Banco Atlántida', null, $cajero->id)['pago'];

    expect($pago->banco)->toBeNull();
});
