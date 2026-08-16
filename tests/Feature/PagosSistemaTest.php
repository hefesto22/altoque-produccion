<?php

declare(strict_types=1);

use App\Domain\Exceptions\PagoNoMarcableException;
use App\Filament\Resources\PagosSistema\PagoSistemaResource;
use App\Models\PagoSistema;
use App\Models\User;
use App\Services\Pagos\PagoSistemaService;
use App\Support\Acceso;
use App\Support\CobroMensual;
use Database\Seeders\RestauranteAccessSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pagos del sistema: las cuotas que el restaurante le paga al desarrollador.
 *
 * Lo que se protege acá: que el plan salga del contrato y no de la memoria de
 * nadie, que un mes no se pueda dar por pagado dos veces, y que el que PAGA
 * (el gerente) no sea el mismo que puede darse por pagado.
 */
beforeEach(function () {
    config()->set('cobro.inicio', '2026-08');
    config()->set('cobro.etapas', [
        ['meses' => 24, 'monto' => 5000.00, 'concepto' => 'Desarrollo, servidor y mantenimiento'],
    ]);
});

it('genera las 24 cuotas del contrato desde agosto 2026', function () {
    $creadas = app(PagoSistemaService::class)->sincronizarPlan();

    expect($creadas)->toBe(24)
        ->and(PagoSistema::query()->count())->toBe(24)
        ->and(round((float) PagoSistema::query()->sum('monto'), 2))->toBe(120000.00);

    $primera = PagoSistema::query()->orderBy('periodo')->firstOrFail();
    $ultima = PagoSistema::query()->orderByDesc('periodo')->firstOrFail();

    expect($primera->periodo->toDateString())->toBe('2026-08-01')
        ->and($primera->numero)->toBe(1)
        ->and($primera->concepto)->toBe('Desarrollo, servidor y mantenimiento')
        // 24 meses desde agosto 2026 terminan en julio de 2028.
        ->and($ultima->periodo->toDateString())->toBe('2028-07-01')
        ->and($ultima->numero)->toBe(24);
});

it('sincronizar dos veces no duplica ni pisa lo ya cobrado', function () {
    app(PagoSistemaService::class)->sincronizarPlan();

    $cuota = PagoSistema::query()->orderBy('periodo')->firstOrFail();
    app(PagoSistemaService::class)->marcarPagada($cuota, 5000.00, 'transferencia');

    // Cambia el contrato: lo ya creado NO se reescribe (es historia).
    config()->set('cobro.etapas', [['meses' => 24, 'monto' => 9999.00, 'concepto' => 'Otro']]);

    expect(app(PagoSistemaService::class)->sincronizarPlan())->toBe(0)
        ->and(PagoSistema::query()->count())->toBe(24)
        ->and((float) $cuota->fresh()->monto)->toBe(5000.00)
        ->and($cuota->fresh()->pagada)->toBeTrue();
});

it('marcar pagada deja monto, forma, fecha y quien lo marco', function () {
    $mauricio = User::factory()->create();
    app(PagoSistemaService::class)->sincronizarPlan();

    $cuota = PagoSistema::query()->orderBy('periodo')->firstOrFail();

    app(PagoSistemaService::class)->marcarPagada(
        $cuota,
        5000.00,
        'transferencia',
        'Banco de Occidente',
        '998877',
        'Primer mes',
        $mauricio->id,
    );

    $fresca = $cuota->fresh();

    expect($fresca->pagada)->toBeTrue()
        ->and((float) $fresca->monto_pagado)->toBe(5000.00)
        ->and($fresca->forma_pago)->toBe('transferencia')
        ->and($fresca->referencia)->toBe('998877')
        ->and($fresca->marcada_por)->toBe($mauricio->id)
        ->and($fresca->pagada_at)->not->toBeNull();

    $resumen = PagoSistema::resumen();

    expect($resumen['total'])->toBe(120000.00)
        ->and($resumen['pagado'])->toBe(5000.00)
        ->and($resumen['saldo'])->toBe(115000.00)
        ->and($resumen['pagadas'])->toBe(1);
});

it('un mes no se puede dar por pagado dos veces', function () {
    app(PagoSistemaService::class)->sincronizarPlan();
    $cuota = PagoSistema::query()->orderBy('periodo')->firstOrFail();

    app(PagoSistemaService::class)->marcarPagada($cuota, 5000.00, 'efectivo');

    expect(fn () => app(PagoSistemaService::class)->marcarPagada($cuota->fresh(), 5000.00, 'efectivo'))
        ->toThrow(PagoNoMarcableException::class);

    // Y el resumen no se infló con el intento.
    expect(PagoSistema::resumen()['pagado'])->toBe(5000.00);
});

it('deshacer devuelve la cuota a pendiente y limpia el cobro', function () {
    app(PagoSistemaService::class)->sincronizarPlan();
    $cuota = PagoSistema::query()->orderBy('periodo')->firstOrFail();

    app(PagoSistemaService::class)->marcarPagada($cuota, 5000.00, 'cheque', 'Banco de Occidente', '112233');
    app(PagoSistemaService::class)->revertir($cuota->fresh(), 'se marcó el mes equivocado');

    $fresca = $cuota->fresh();

    expect($fresca->pagada)->toBeFalse()
        ->and($fresca->monto_pagado)->toBeNull()
        ->and($fresca->forma_pago)->toBeNull()
        ->and(PagoSistema::resumen()['pagado'])->toBe(0.0);
});

it('una cuota vieja sin pagar sale atrasada, la del mes en curso no', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00'));

    app(PagoSistemaService::class)->sincronizarPlan();

    $agosto = PagoSistema::query()->whereDate('periodo', '2026-08-01')->firstOrFail();
    $octubre = PagoSistema::query()->whereDate('periodo', '2026-10-01')->firstOrFail();
    $noviembre = PagoSistema::query()->whereDate('periodo', '2026-11-01')->firstOrFail();

    // El mes en curso NO es atraso: sería alarma falsa todos los meses.
    expect($agosto->estado())->toBe('vencida')
        ->and($octubre->estado())->toBe('actual')
        ->and($noviembre->estado())->toBe('futura')
        ->and(PagoSistema::query()->atrasadas()->count())->toBe(2); // agosto y septiembre

    Carbon::setTestNow();
});

it('si el mes ya se marco pagado el aviso deja de molestar al gerente', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-05 18:00:00'));

    app(PagoSistemaService::class)->sincronizarPlan();
    $periodo = CobroMensual::periodo(now());

    expect(CobroMensual::yaPagado($periodo))->toBeFalse();

    $septiembre = PagoSistema::query()->whereDate('periodo', '2026-09-01')->firstOrFail();
    app(PagoSistemaService::class)->marcarPagada($septiembre, 5000.00, 'transferencia');

    expect(CobroMensual::yaPagado($periodo))->toBeTrue();

    Carbon::setTestNow();
});

it('el gerente ve los pagos pero NO puede marcarlos', function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $gerente = User::factory()->create();
    $gerente->assignRole('gerente');

    // Es el único del restaurante que lo ve: quien paga tiene que poder
    // revisar cuánto lleva. Pero darse por pagado es del super_admin.
    expect($gerente->can('ViewAny:PagoSistema'))->toBeTrue()
        ->and($gerente->can('Update:PagoSistema'))->toBeFalse();

    foreach (['administrador', 'cajero', 'mesero', 'contador'] as $rol) {
        $otro = User::factory()->create();
        $otro->assignRole($rol);

        expect($otro->can('ViewAny:PagoSistema'))->toBeFalse("el rol {$rol} no debería ver los pagos del sistema");
    }
});

it('el super_admin si puede marcar aunque nadie tenga ese permiso', function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $mauricio = User::factory()->create();
    $mauricio->assignRole('super_admin');
    $this->actingAs($mauricio);

    expect(Acceso::puede('Update:PagoSistema'))->toBeTrue()
        ->and(PagoSistemaResource::getPages())->toHaveKey('index');
});
