<?php

declare(strict_types=1);

use App\Services\Pos\TicketDiarioService;
use Illuminate\Support\Carbon;

it('numera por tipo con su prefijo', function () {
    $svc = app(TicketDiarioService::class);

    expect($svc->siguiente('local'))->toBe('LOC-1')
        ->and($svc->siguiente('llevar'))->toBe('LL-1')
        ->and($svc->siguiente('domicilio'))->toBe('DOM-1');
});

it('incrementa la secuencia dentro del mismo día y tipo, independiente por tipo', function () {
    $svc = app(TicketDiarioService::class);

    expect($svc->siguiente('local'))->toBe('LOC-1')
        ->and($svc->siguiente('local'))->toBe('LOC-2')
        ->and($svc->siguiente('local'))->toBe('LOC-3')
        ->and($svc->siguiente('llevar'))->toBe('LL-1'); // su propia secuencia
});

it('reinicia el contador cada día', function () {
    $svc = app(TicketDiarioService::class);

    Carbon::setTestNow('2026-06-30 10:00:00');
    expect($svc->siguiente('local'))->toBe('LOC-1')
        ->and($svc->siguiente('local'))->toBe('LOC-2');

    Carbon::setTestNow('2026-07-01 08:00:00');
    expect($svc->siguiente('local'))->toBe('LOC-1'); // nuevo día: arranca en 1

    Carbon::setTestNow();
});
