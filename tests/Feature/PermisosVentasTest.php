<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Acceso;
use Database\Seeders\RestauranteAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Frontera de acceso confirmada con Mauricio (2026-07-02):
 * el cajero VE el listado de ventas (reimprimir/verificar) pero NO anula
 * facturas. Anular es permiso explícito (anular_factura), gestionable
 * desde la pantalla de Roles — nunca lista de roles hardcodeada.
 */
beforeEach(function () {
    // panel_user lo crea Shield en el seeder principal; aquí sembramos solo
    // el de acceso del restaurante, así que hay que crearlo antes.
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);

    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('el cajero puede ver ventas pero no anular facturas', function () {
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    Auth::login($cajero);

    expect(Acceso::puede('view_any_venta'))->toBeTrue()
        ->and(Acceso::puede('anular_factura'))->toBeFalse();
});

it('el administrador puede anular facturas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    Auth::login($admin);

    expect(Acceso::puede('anular_factura'))->toBeTrue();
});

it('el gerente no anula facturas (solo lectura sobre facturación)', function () {
    $gerente = User::factory()->create();
    $gerente->assignRole('gerente');
    Auth::login($gerente);

    expect(Acceso::puede('view_any_venta'))->toBeTrue()
        ->and(Acceso::puede('anular_factura'))->toBeFalse();
});

it('el super_admin pasa cualquier permiso sin tenerlo asignado', function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $root = User::factory()->create();
    $root->assignRole('super_admin');
    Auth::login($root);

    expect(Acceso::puede('anular_factura'))->toBeTrue()
        ->and(Acceso::puede('permiso_que_no_existe'))->toBeTrue();
});

it('sin usuario autenticado no hay permiso alguno', function () {
    Auth::logout();

    expect(Acceso::puede('view_any_venta'))->toBeFalse();
});
