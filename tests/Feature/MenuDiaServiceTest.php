<?php

declare(strict_types=1);

use App\Models\Producto;
use App\Models\Servicio;
use App\Services\Pos\MenuDiaService;
use Illuminate\Support\Carbon;

it('detecta el servicio activo según la hora', function () {
    Servicio::factory()->create(['slug' => 'desayuno', 'nombre' => 'Desayuno', 'hora_inicio' => '06:00:00', 'hora_fin' => '10:30:00', 'orden' => 1]);
    $almuerzo = Servicio::factory()->create(['slug' => 'almuerzo', 'nombre' => 'Almuerzo', 'hora_inicio' => '11:00:00', 'hora_fin' => '15:00:00', 'orden' => 2]);

    $mediodia = Carbon::today()->setTime(12, 0);

    expect(Servicio::activoAhora($mediodia)?->id)->toBe($almuerzo->id);
});

it('muestra todo el catálogo activo si la fecha no tiene menú cargado', function () {
    Producto::factory()->count(3)->create();

    $disponibles = app(MenuDiaService::class)->disponibles(Carbon::today(), null);

    expect($disponibles)->toHaveCount(3);
});

it('filtra por el menú del día cuando está cargado', function () {
    $servicio = Servicio::factory()->create();
    $pollo = Producto::factory()->create();
    Producto::factory()->create(); // otro producto que NO va en el menú

    $svc = app(MenuDiaService::class);
    $svc->sincronizar(Carbon::today(), $servicio->id, [$pollo->id]);

    $disponibles = $svc->disponibles(Carbon::today(), $servicio->id);

    expect($disponibles)->toHaveCount(1)
        ->and($disponibles->first()->id)->toBe($pollo->id);
});

it('sincronizar reemplaza la selección anterior del servicio', function () {
    $servicio = Servicio::factory()->create();
    $p1 = Producto::factory()->create();
    $p2 = Producto::factory()->create();

    $svc = app(MenuDiaService::class);
    $svc->sincronizar(Carbon::today(), $servicio->id, [$p1->id]);
    $svc->sincronizar(Carbon::today(), $servicio->id, [$p2->id]);

    expect($svc->seleccionActual(Carbon::today(), $servicio->id))->toBe([$p2->id]);
});
