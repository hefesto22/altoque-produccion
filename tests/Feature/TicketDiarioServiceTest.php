<?php

declare(strict_types=1);

use App\Services\Pos\TicketDiarioService;
use Illuminate\Support\Carbon;

/**
 * Secuencia ÚNICA compartida entre tipos de orden (pedido del restaurante,
 * 2026-07-03): LOC-1, LL-2, LOC-3… El prefijo identifica el tipo; el número
 * corre parejo para que cocina despache en orden real de llegada.
 */
it('la secuencia es una sola para todos los tipos, con el prefijo de cada uno', function () {
    $svc = app(TicketDiarioService::class);

    expect($svc->siguiente('local'))->toBe('LOC-1')
        ->and($svc->siguiente('llevar'))->toBe('LL-2')
        ->and($svc->siguiente('local'))->toBe('LOC-3')
        ->and($svc->siguiente('domicilio'))->toBe('DOM-4');
});

it('reinicia el contador cada día', function () {
    $svc = app(TicketDiarioService::class);

    Carbon::setTestNow('2026-06-30 10:00:00');
    expect($svc->siguiente('local'))->toBe('LOC-1')
        ->and($svc->siguiente('llevar'))->toBe('LL-2');

    Carbon::setTestNow('2026-07-01 08:00:00');
    expect($svc->siguiente('local'))->toBe('LOC-1'); // nuevo día: arranca en 1

    Carbon::setTestNow();
});
