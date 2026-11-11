<?php

declare(strict_types=1);

use App\Livewire\MenuPantalla;
use Illuminate\Support\Carbon;

it('anuncia cada carne de un tier compartido con su precio', function () {
    expect(MenuPantalla::lineaIndividual('Pollo / Cerdo', ['60.00', '60.00']))->toBe('POLLO L.60 Ó CERDO L.60')
        ->and(MenuPantalla::lineaIndividual('Res', ['70.00']))->toBe('RES L.70');
});

it('ignora los precios en cero al sacar el precio de la porción', function () {
    // Caso real: POLLO EN CREMA DE HONGOS quedó en L.0 y la TV anunciaba "L.0".
    expect(MenuPantalla::lineaIndividual('Pollo / Cerdo', ['60.00', '0.00', '65.00']))->toBe('POLLO L.60 Ó CERDO L.60')
        ->and(MenuPantalla::lineaIndividual('Pollo / Cerdo', ['0.00']))->toBeNull();
});

it('la temporada navideña va del 1 de noviembre al 6 de enero', function (string $fecha, bool $esperado) {
    expect(MenuPantalla::esTemporadaNavidad(Carbon::parse($fecha)))->toBe($esperado);
})->with([
    ['2026-10-31', false],
    ['2026-11-01', true],
    ['2026-12-25', true],
    ['2027-01-06', true],
    ['2027-01-07', false],
    ['2027-06-15', false],
]);
