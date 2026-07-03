<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Acceso;
use Database\Seeders\RestauranteAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
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
        ->and(Acceso::puede('AnularFactura'))->toBeFalse();
});

it('el administrador puede anular facturas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    Auth::login($admin);

    expect(Acceso::puede('AnularFactura'))->toBeTrue();
});

it('el gerente ve ventas y también anula facturas', function () {
    // Decisión de Mauricio (2026-07-03): el gerente supervisa la caja y
    // puede anular; el único que NO anula es el cajero.
    $gerente = User::factory()->create();
    $gerente->assignRole('gerente');
    Auth::login($gerente);

    expect(Acceso::puede('view_any_venta'))->toBeTrue()
        ->and(Acceso::puede('AnularFactura'))->toBeTrue();
});

it('el super_admin pasa cualquier permiso sin tenerlo asignado', function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $root = User::factory()->create();
    $root->assignRole('super_admin');
    Auth::login($root);

    expect(Acceso::puede('AnularFactura'))->toBeTrue()
        ->and(Acceso::puede('permiso_que_no_existe'))->toBeTrue();
});

it('sin usuario autenticado no hay permiso alguno', function () {
    Auth::logout();

    expect(Acceso::puede('view_any_venta'))->toBeFalse();
});

/**
 * El registro de actividad es de auditoría: solo quien tenga
 * ViewAny:Activity (hoy: super_admin vía Shield). La policy del modelo
 * de Spatie se registra a mano en AppServiceProvider — Laravel no la
 * auto-descubre por estar el modelo fuera de App\Models.
 */
it('ni el cajero ni el administrador ven el registro de actividad', function () {
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');

    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    expect(Gate::forUser($cajero)->denies('viewAny', Activity::class))->toBeTrue()
        ->and(Gate::forUser($admin)->denies('viewAny', Activity::class))->toBeTrue();
});

/**
 * Abrir turno es de quien entrega el fondo (gerente/administrador).
 * El cajero no abre su propio turno; se lo abren desde Cortes De Caja.
 */
it('solo gerente y administrador pueden abrir turnos de caja', function () {
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    Auth::login($cajero);
    expect(Acceso::puede('AbrirTurno'))->toBeFalse();

    $gerente = User::factory()->create();
    $gerente->assignRole('gerente');
    Auth::login($gerente);
    expect(Acceso::puede('AbrirTurno'))->toBeTrue();

    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    Auth::login($admin);
    expect(Acceso::puede('AbrirTurno'))->toBeTrue();
});

it('quien tiene ViewAny:Activity sí ve el registro de actividad', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:Activity', 'guard_name' => 'web']);

    $auditor = User::factory()->create();
    $auditor->givePermissionTo('ViewAny:Activity');

    expect(Gate::forUser($auditor)->allows('viewAny', Activity::class))->toBeTrue();
});
