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
        ['meses' => 12, 'monto' => 5000.00, 'concepto' => 'Desarrollo, servidor y mantenimiento'],
        ['meses' => 12, 'monto' => 3450.00, 'concepto' => 'Servidor y mantenimiento (L. 3,000 + 15%)'],
    ]);
});

it('genera las 24 cuotas en dos tramos: 5,000 el primer año y 3,450 el segundo', function () {
    $creadas = app(PagoSistemaService::class)->sincronizarPlan();

    // 12 x 5,000 + 12 x 3,450 = 101,400.
    expect($creadas)->toBe(24)
        ->and(PagoSistema::query()->count())->toBe(24)
        ->and(round((float) PagoSistema::query()->sum('monto'), 2))->toBe(101400.00);

    $primera = PagoSistema::query()->orderBy('periodo')->firstOrFail();
    $ultimaDelAno1 = PagoSistema::query()->where('numero', 12)->firstOrFail();
    $primeraDelAno2 = PagoSistema::query()->where('numero', 13)->firstOrFail();
    $ultima = PagoSistema::query()->orderByDesc('periodo')->firstOrFail();

    expect($primera->periodo->toDateString())->toBe('2026-08-01')
        ->and((float) $primera->monto)->toBe(5000.00)
        ->and($primera->concepto)->toBe('Desarrollo, servidor y mantenimiento')
        // El tramo caro termina en julio 2027 y el barato arranca en agosto.
        ->and($ultimaDelAno1->periodo->toDateString())->toBe('2027-07-01')
        ->and((float) $ultimaDelAno1->monto)->toBe(5000.00)
        ->and($primeraDelAno2->periodo->toDateString())->toBe('2027-08-01')
        ->and((float) $primeraDelAno2->monto)->toBe(3450.00)
        ->and($ultima->periodo->toDateString())->toBe('2028-07-01')
        ->and($ultima->numero)->toBe(24);
});

it('un cargo extra se suma al total sin deformar el contrato', function () {
    app(PagoSistemaService::class)->sincronizarPlan();

    app(PagoSistemaService::class)->agregarCargo(
        'Módulo de inventario',
        15000.00,
        Carbon::parse('2026-11-01'),
        'Lo pidió el cliente en octubre',
    );

    $r = PagoSistema::resumen();

    expect($r['contrato'])->toBe(101400.00)   // el contrato NO se movió
        ->and($r['extras'])->toBe(15000.00)
        ->and($r['total'])->toBe(116400.00)
        ->and($r['cuotas'])->toBe(24)         // el extra no es una cuota
        ->and(PagoSistema::query()->extras()->count())->toBe(1);

    // Y sincronizar de nuevo no lo toca ni lo borra.
    app(PagoSistemaService::class)->sincronizarPlan();

    expect(PagoSistema::query()->extras()->count())->toBe(1)
        ->and(PagoSistema::resumen()['total'])->toBe(116400.00);
});

it('un extra pagado NO tapa el aviso de la mensualidad de ese mes', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-05 18:00:00'));

    app(PagoSistemaService::class)->sincronizarPlan();

    $extra = app(PagoSistemaService::class)->agregarCargo('Módulo nuevo', 15000.00, Carbon::parse('2026-11-01'));
    app(PagoSistemaService::class)->marcarPagada($extra, 15000.00, 'transferencia');

    // Pagó el módulo, no la mensualidad: el aviso tiene que seguir saliendo.
    expect(CobroMensual::yaPagado(Carbon::parse('2026-11-01')))->toBeFalse();

    Carbon::setTestNow();
});

it('si se renegocia el trato: lo pagado queda, lo pendiente se actualiza', function () {
    app(PagoSistemaService::class)->sincronizarPlan();

    $cuota1 = PagoSistema::query()->where('numero', 1)->firstOrFail();
    app(PagoSistemaService::class)->marcarPagada($cuota1, 5000.00, 'transferencia');

    config()->set('cobro.etapas', [['meses' => 24, 'monto' => 9999.00, 'concepto' => 'Otro']]);

    expect(app(PagoSistemaService::class)->sincronizarPlan())->toBe(0)
        ->and(PagoSistema::query()->count())->toBe(24);

    // Agosto ya se cobró: sigue diciendo lo que decía el día que entró la
    // plata. Septiembre no se ha pagado: sigue el trato nuevo.
    expect((float) $cuota1->fresh()->monto)->toBe(5000.00)
        ->and($cuota1->fresh()->pagada)->toBeTrue()
        ->and((float) PagoSistema::query()->where('numero', 2)->firstOrFail()->monto)->toBe(9999.00);
});

it('si el contrato se acorta, las cuotas de mas sin pagar se van', function () {
    app(PagoSistemaService::class)->sincronizarPlan();

    expect(PagoSistema::query()->delPlan()->count())->toBe(24);

    config()->set('cobro.etapas', [['meses' => 6, 'monto' => 5000.00, 'concepto' => 'Recortado']]);
    app(PagoSistemaService::class)->sincronizarPlan();

    expect(PagoSistema::query()->delPlan()->count())->toBe(6);
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

    expect($resumen['total'])->toBe(101400.00)
        ->and($resumen['pagado'])->toBe(5000.00)
        ->and($resumen['saldo'])->toBe(96400.00)
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
