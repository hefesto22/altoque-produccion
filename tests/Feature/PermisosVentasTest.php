<?php

declare(strict_types=1);

use App\Models\Producto;
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
 * Fronteras de acceso confirmadas con Mauricio. Tras la migración a Shield
 * (2026-07-03) TODO acceso pasa por un permiso `Accion:Modelo` /
 * `View:Pagina` editable en la pantalla de Roles: policies para los
 * Resources, canAccess por permiso en las Pages, Acceso::puede en acciones.
 * Nunca listas de roles hardcodeadas.
 */
beforeEach(function () {
    // panel_user lo crea Shield en el seeder principal; aquí sembramos solo
    // el de acceso del restaurante, así que hay que crearlo antes.
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);

    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function usuarioConRol(string $rol): User
{
    $user = User::factory()->create();
    $user->assignRole($rol);

    return $user;
}

it('el cajero puede ver ventas pero no anular facturas', function () {
    Auth::login(usuarioConRol('cajero'));

    expect(Acceso::puede('ViewAny:Venta'))->toBeTrue()
        ->and(Acceso::puede('AnularFactura'))->toBeFalse();
});

it('el administrador puede anular facturas', function () {
    Auth::login(usuarioConRol('administrador'));

    expect(Acceso::puede('AnularFactura'))->toBeTrue();
});

it('el gerente ve ventas y también anula facturas', function () {
    // Decisión de Mauricio (2026-07-03): el gerente supervisa la caja y
    // puede anular; el único que NO anula es el cajero.
    Auth::login(usuarioConRol('gerente'));

    expect(Acceso::puede('ViewAny:Venta'))->toBeTrue()
        ->and(Acceso::puede('AnularFactura'))->toBeTrue();
});

it('el super_admin pasa cualquier permiso sin tenerlo asignado', function () {
    $root = User::factory()->create();
    $root->assignRole('super_admin');
    Auth::login($root);

    expect(Acceso::puede('AnularFactura'))->toBeTrue()
        ->and(Acceso::puede('permiso_que_no_existe'))->toBeTrue();
});

it('sin usuario autenticado no hay permiso alguno', function () {
    Auth::logout();

    expect(Acceso::puede('ViewAny:Venta'))->toBeFalse();
});

/*
 * ── Policies de Resources: la matriz del seeder gobierna ─────────────────
 */

it('el cajero no ve el menú de productos pero el gerente sí lo administra', function () {
    $cajero = usuarioConRol('cajero');
    $gerente = usuarioConRol('gerente');

    expect(Gate::forUser($cajero)->denies('viewAny', Producto::class))->toBeTrue()
        ->and(Gate::forUser($gerente)->allows('viewAny', Producto::class))->toBeTrue()
        ->and(Gate::forUser($gerente)->allows('create', Producto::class))->toBeTrue();
});

it('corregir un corte de caja (Update:CorteCaja) es solo del administrador', function () {
    // La CorteCajaPolicy::update delega 1:1 en este permiso; la acción
    // "corregir" del Resource lo chequea con Acceso::puede.
    expect(usuarioConRol('cajero')->can('Update:CorteCaja'))->toBeFalse()
        ->and(usuarioConRol('gerente')->can('Update:CorteCaja'))->toBeFalse()
        ->and(usuarioConRol('administrador')->can('Update:CorteCaja'))->toBeTrue();
});

/*
 * ── Permisos de página (View:Pagina) ─────────────────────────────────────
 */

it('el cajero entra al POS pero no al menú del día ni a lo fiscal', function () {
    Auth::login(usuarioConRol('cajero'));

    expect(Acceso::puede('View:PuntoDeVenta'))->toBeTrue()
        ->and(Acceso::puede('View:Cocina'))->toBeTrue()
        ->and(Acceso::puede('View:MenuDelDia'))->toBeFalse()
        ->and(Acceso::puede('View:LibrosFiscales'))->toBeFalse();
});

it('el contador ve lo fiscal pero no opera el POS', function () {
    Auth::login(usuarioConRol('contador'));

    expect(Acceso::puede('View:LibrosFiscales'))->toBeTrue()
        ->and(Acceso::puede('View:DeclaracionIsvMensual'))->toBeTrue()
        ->and(Acceso::puede('ExportVentas'))->toBeTrue()
        ->and(Acceso::puede('View:PuntoDeVenta'))->toBeFalse();
});

/**
 * El registro de actividad es de auditoría: solo quien tenga
 * ViewAny:Activity (hoy: super_admin, que sincroniza todos los permisos).
 * La policy del modelo de Spatie se registra a mano en AppServiceProvider —
 * Laravel no la auto-descubre por estar el modelo fuera de App\Models.
 */
it('ni el cajero ni el administrador ven el registro de actividad', function () {
    $cajero = usuarioConRol('cajero');
    $admin = usuarioConRol('administrador');

    expect(Gate::forUser($cajero)->denies('viewAny', Activity::class))->toBeTrue()
        ->and(Gate::forUser($admin)->denies('viewAny', Activity::class))->toBeTrue();
});

/**
 * Abrir turno es de quien entrega el fondo (gerente/administrador).
 * El cajero no abre su propio turno; se lo abren desde Cortes De Caja.
 */
it('solo gerente y administrador pueden abrir turnos de caja', function () {
    Auth::login(usuarioConRol('cajero'));
    expect(Acceso::puede('AbrirTurno'))->toBeFalse();

    Auth::login(usuarioConRol('gerente'));
    expect(Acceso::puede('AbrirTurno'))->toBeTrue();

    Auth::login(usuarioConRol('administrador'));
    expect(Acceso::puede('AbrirTurno'))->toBeTrue();
});

it('quien tiene ViewAny:Activity sí ve el registro de actividad', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:Activity', 'guard_name' => 'web']);

    $auditor = User::factory()->create();
    $auditor->givePermissionTo('ViewAny:Activity');

    expect(Gate::forUser($auditor)->allows('viewAny', Activity::class))->toBeTrue();
});
