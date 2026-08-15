<?php

declare(strict_types=1);

use App\Models\CuentaPrepago;
use App\Services\Cuentas\CuentaPrepagoService;
use Illuminate\Support\Facades\URL;

/**
 * El link que el cliente abre desde su WhatsApp para ver cuánto le queda.
 * Público pero FIRMADO y por token: no se adivina ni se salta de una cuenta
 * a otra cambiando un número.
 */
function cuentaConMovimientos(): CuentaPrepago
{
    $cuenta = CuentaPrepago::create([
        'nombre'   => 'inversiones olympo',
        'rtn'      => '13212003002192',
        'telefono' => '33012826',
    ]);

    $svc = app(CuentaPrepagoService::class);
    $svc->depositar($cuenta, 10000.00, 'transferencia', 'Banco de Occidente', 'REF-8891');
    $svc->consumir($cuenta, 350.00);

    return $cuenta->fresh();
}

it('muestra el saldo, lo depositado y lo consumido', function () {
    $cuenta = cuentaConMovimientos();

    $this->get($cuenta->urlEstado())
        ->assertOk()
        ->assertSee('INVERSIONES OLYMPO')
        ->assertSee('9,650.00')      // saldo
        ->assertSee('10,000.00')     // depositado
        ->assertSee('350.00')        // consumido
        ->assertSee('Banco de Occidente')
        ->assertSee('REF-8891');
});

it('deja claro que no es un documento fiscal', function () {
    // Cada consumo tiene su factura: esta página es solo el resumen.
    $this->get(cuentaConMovimientos()->urlEstado())
        ->assertOk()
        ->assertSee('no es un documento fiscal');
});

it('sin firma no se abre', function () {
    $cuenta = cuentaConMovimientos();

    $this->get(route('cuentas.estado', ['token' => $cuenta->token]))
        ->assertForbidden();
});

it('con un token inventado no se llega a ninguna cuenta', function () {
    cuentaConMovimientos();

    // Firma válida pero token que no existe: 404, no la cuenta de otro.
    $url = URL::signedRoute('cuentas.estado', ['token' => str_repeat('x', 40)]);

    $this->get($url)->assertNotFound();
});

it('una cuenta sin movimientos abre igual y lo dice', function () {
    $cuenta = CuentaPrepago::create(['nombre' => 'CUENTA NUEVA']);

    $this->get($cuenta->urlEstado())
        ->assertOk()
        ->assertSee('Todavía no hay movimientos');
});
