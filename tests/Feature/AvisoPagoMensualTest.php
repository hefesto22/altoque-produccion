<?php

declare(strict_types=1);

use App\Livewire\AvisoPagoMensual;
use App\Models\AvisoPago;
use App\Models\User;
use App\Support\CobroMensual;
use Database\Seeders\RestauranteAccessSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aviso del pago mensual del sistema (2026-08-01).
 *
 * Contrato (2026-08-16), en dos tramos: L. 5,000 al mes de agosto 2026 a
 * julio 2027 (desarrollo, servidor y mantenimiento) y L. 3,450 de agosto 2027
 * a julio 2028 (servidor y mantenimiento, L. 3,000 + 15%). L. 101,400 en
 * total. El aviso sale el día 1 a las 5:00 p.m., solo al gerente, y se
 * esconde hasta el mes siguiente cuando lo marca como recibido (o cuando el
 * pago de ese mes queda registrado en Pagos).
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function usuario(string $rol): User
{
    $user = User::factory()->create();
    $user->assignRole($rol);

    return $user;
}

it('cobra 5,000 el primer año y 3,450 el segundo', function () {
    expect(CobroMensual::monto(Carbon::parse('2026-08-01')))->toBe(5000.00)      // primer mes
        ->and(CobroMensual::monto(Carbon::parse('2027-07-01')))->toBe(5000.00)   // último del año 1
        ->and(CobroMensual::monto(Carbon::parse('2027-08-01')))->toBe(3450.00)   // arranca el año 2
        ->and(CobroMensual::monto(Carbon::parse('2028-07-01')))->toBe(3450.00)   // último mes
        ->and(CobroMensual::monto(Carbon::parse('2028-08-01')))->toBeNull()      // contrato terminado
        ->and(CobroMensual::monto(Carbon::parse('2026-07-01')))->toBeNull();     // antes del inicio

    // El concepto también cambia: en el año 2 ya no se paga desarrollo.
    expect(CobroMensual::concepto(Carbon::parse('2026-08-01')))
        ->not->toBe(CobroMensual::concepto(Carbon::parse('2027-08-01')));
});

it('el día 1 no aparece antes de las 5 de la tarde', function () {
    expect(CobroMensual::toca(Carbon::parse('2026-09-01 16:59')))->toBeFalse()
        ->and(CobroMensual::toca(Carbon::parse('2026-09-01 17:00')))->toBeTrue()
        ->and(CobroMensual::toca(Carbon::parse('2026-09-02 08:00')))->toBeTrue();
});

it('el gerente ve el aviso con el monto y la cuenta', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 17:30'));

    Livewire::actingAs(usuario('gerente'))
        ->test(AvisoPagoMensual::class)
        ->assertSee('5,000.00')
        ->assertSee('211220102994')
        ->assertSee('Recordatorio recibido');

    Carbon::setTestNow();
});

it('el cajero y el administrador NO ven el aviso', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 17:30'));

    foreach (['cajero', 'administrador', 'contador'] as $rol) {
        Livewire::actingAs(usuario($rol))
            ->test(AvisoPagoMensual::class)
            ->assertDontSee('211220102994');
    }

    Carbon::setTestNow();
});

it('marcarlo como recibido lo esconde y queda registrado', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 17:30'));
    $gerente = usuario('gerente');

    Livewire::actingAs($gerente)
        ->test(AvisoPagoMensual::class)
        ->call('marcarRecibido')
        ->assertDontSee('211220102994');

    $aviso = AvisoPago::query()->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->periodo->toDateString())->toBe('2026-09-01')
        ->and($aviso->user_id)->toBe($gerente->id);

    // El mes siguiente vuelve a salir: se marcó SOLO septiembre.
    Carbon::setTestNow(Carbon::parse('2026-10-01 17:30'));

    Livewire::actingAs($gerente)
        ->test(AvisoPagoMensual::class)
        ->assertSee('211220102994');

    Carbon::setTestNow();
});
