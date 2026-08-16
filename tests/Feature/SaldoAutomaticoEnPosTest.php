<?php

declare(strict_types=1);

use App\Filament\Pages\PuntoDeVenta;
use App\Models\Cliente;
use App\Models\CuentaPrepago;
use App\Models\Producto;
use App\Models\User;
use App\Services\Cuentas\CuentaPrepagoService;
use Database\Seeders\RestauranteAccessSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El saldo se elige SOLO (2026-08-15).
 *
 * En pruebas pasó esto: la caja escribió el RTN, salió el recuadro verde con
 * los L. 10,000 de la empresa... y la venta se cobró en efectivo porque nadie
 * tocó el botón "Saldo". Detectar la cuenta sin usarla no sirve de nada, así
 * que la forma de pago se pone en "saldo" sola.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $this->actingAs($admin);

    $this->bebida = Producto::factory()->create([
        'categoria' => 'bebida', 'nombre' => 'Refresco', 'precio' => 100.00, 'grava_isv' => true,
    ]);
});

/** @param array<string, mixed> $extra */
function cuentaDelPos(string $rtn, string $nombre, float $monto, array $extra = []): CuentaPrepago
{
    $cuenta = CuentaPrepago::create([
        'nombre'     => $nombre,
        'cliente_id' => Cliente::registrar($rtn, $nombre)->id,
        ...$extra,
    ]);

    if ($monto > 0) {
        app(CuentaPrepagoService::class)->depositar($cuenta, $monto, 'transferencia', 'Banco de Occidente');
    }

    return $cuenta->fresh();
}

it('al escribir el RTN de una empresa con saldo la forma de pago pasa sola a saldo', function () {
    cuentaDelPos('13212003002192', 'INVERSIONES OLYMPO', 10000.00);

    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->bebida->id)
        ->set('rtnInput', '13212003002192');

    expect($pos->get('formaPago'))->toBe('saldo');
});

it('si el RTN cambia a uno sin cuenta la forma de pago vuelve a efectivo', function () {
    cuentaDelPos('13212003002192', 'INVERSIONES OLYMPO', 10000.00);

    // Sin esto, el "saldo" de la venta anterior queda pegado y la siguiente
    // factura se traba con "Ese RTN no tiene cuenta con saldo".
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->bebida->id)
        ->set('rtnInput', '13212003002192');

    expect($pos->get('formaPago'))->toBe('saldo');

    $pos->set('rtnInput', '08011990123456');

    expect($pos->get('formaPago'))->toBe('efectivo');
});

it('si no alcanza ni con el credito no se elige saldo sola', function () {
    cuentaDelPos('13212003002192', 'INVERSIONES OLYMPO', 10.00, [
        'permite_credito' => false, 'limite_credito' => 0,
    ]);

    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->bebida->id)   // L. 100 contra L. 10
        ->set('rtnInput', '13212003002192');

    expect($pos->get('formaPago'))->toBe('efectivo');
});

it('no pisa la tarjeta si el cajero ya la eligio a mano', function () {
    cuentaDelPos('13212003002192', 'INVERSIONES OLYMPO', 10000.00);

    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->bebida->id)
        ->set('formaPago', 'tarjeta')
        ->set('rtnInput', '13212003002192');

    expect($pos->get('formaPago'))->toBe('tarjeta');
});

it('elegir el cliente de las sugerencias tambien enciende el saldo', function () {
    cuentaDelPos('13212003002192', 'INVERSIONES OLYMPO', 10000.00);

    // elegirCliente() llena el RTN por codigo: el hook updatedRtnInput NO corre.
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->bebida->id)
        ->call('elegirCliente', '13212003002192', 'INVERSIONES OLYMPO');

    expect($pos->get('formaPago'))->toBe('saldo');
});
