<?php

declare(strict_types=1);

use App\Models\ComboEspecial;
use App\Models\MenuDia;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Menu\PlatoDelDiaService;
use App\Services\Pos\MenuDiaService;
use Illuminate\Support\Carbon;

/** Los tres servicios del local, como en producción. */
function serviciosDelLocal(): void
{
    Servicio::factory()->create(['slug' => 'desayuno', 'nombre' => 'Desayuno', 'hora_inicio' => '06:00:00', 'hora_fin' => '10:30:00', 'orden' => 1]);
    Servicio::factory()->create(['slug' => 'almuerzo', 'nombre' => 'Almuerzo', 'hora_inicio' => '11:00:00', 'hora_fin' => '15:00:00', 'orden' => 2]);
    Servicio::factory()->create(['slug' => 'cena', 'nombre' => 'Cena', 'hora_inicio' => '17:00:00', 'hora_fin' => '21:00:00', 'orden' => 3]);
}

it('publica el plato del día en los tres servicios de esa fecha', function () {
    serviciosDelLocal();
    $pollo = Producto::factory()->proteina()->create();
    $arroz = Producto::factory()->create(['categoria' => 'complemento']);

    $plato = app(PlatoDelDiaService::class)->crear(
        Carbon::today(),
        'Sopa de caracol',
        180.00,
        [$pollo->id, $arroz->id],
        'Incluye tortillas',
    );

    expect($plato->categoria)->toBe('combo')
        ->and($plato->combo_modo)->toBe('platillo')
        ->and($plato->fecha_especial->toDateString())->toBe(Carbon::today()->toDateString())
        ->and($plato->items()->count())->toBe(2)
        ->and(MenuDia::query()->where('producto_id', $plato->id)->count())->toBe(3);
});

it('el especial de otro día no se cuela en el menú de hoy', function () {
    serviciosDelLocal();
    Producto::factory()->create(); // catálogo permanente

    app(PlatoDelDiaService::class)->crear(Carbon::yesterday(), 'Especial de ayer', 150.00, []);

    $hoy = app(MenuDiaService::class)->disponibles(Carbon::today(), null);

    expect($hoy->pluck('nombre'))->not->toContain('Especial de ayer');
});

it('el especial de hoy sí aparece en el menú de hoy', function () {
    serviciosDelLocal();
    $almuerzo = Servicio::query()->where('slug', 'almuerzo')->firstOrFail();

    app(PlatoDelDiaService::class)->crear(Carbon::today(), 'Especial de hoy', 150.00, []);

    $disponibles = app(MenuDiaService::class)->disponibles(Carbon::today(), $almuerzo->id);

    expect($disponibles->pluck('nombre'))->toContain('Especial de hoy');
});

it('no ensucia el catálogo permanente de platillos completos', function () {
    serviciosDelLocal();
    ComboEspecial::query()->create(['nombre' => 'Combo Familiar', 'precio' => 300, 'combo_modo' => 'platillo', 'activo' => true, 'grava_isv' => true]);

    app(PlatoDelDiaService::class)->crear(Carbon::today(), 'Especial de hoy', 150.00, []);

    $catalogo = ComboEspecial::query()->delCatalogo()->pluck('nombre');

    expect($catalogo)->toContain('Combo Familiar')
        ->and($catalogo)->not->toContain('Especial de hoy');
});

it('un plato del día sin ventas se borra por completo', function () {
    serviciosDelLocal();
    $svc = app(PlatoDelDiaService::class);
    $plato = $svc->crear(Carbon::today(), 'Especial sin vender', 150.00, []);

    expect($svc->eliminar($plato))->toBeTrue()
        ->and(ComboEspecial::query()->find($plato->id))->toBeNull()
        ->and(MenuDia::query()->where('producto_id', $plato->id)->exists())->toBeFalse();
});

it('un plato del día ya vendido NO se borra: solo sale del menú', function () {
    serviciosDelLocal();
    $svc = app(PlatoDelDiaService::class);
    $plato = $svc->crear(Carbon::today(), 'Especial vendido', 150.00, []);

    $venta = Venta::create([
        'cajero_id' => User::factory()->create()->id, 'tipo' => 'recibo', 'total' => 150, 'vendida_at' => now(),
    ]);
    VentaItem::query()->create([
        'venta_id'        => $venta->id,
        'producto_id'     => $plato->id,
        'nombre'          => $plato->nombre,
        'precio_unitario' => 150,
        'cantidad'        => 1,
        'grava_isv'       => true,
        'importe'         => 150,
    ]);

    expect($svc->eliminar($plato))->toBeFalse()
        ->and(ComboEspecial::query()->find($plato->id))->not->toBeNull()
        ->and(ComboEspecial::query()->find($plato->id)->activo)->toBeFalse()
        ->and(MenuDia::query()->where('producto_id', $plato->id)->exists())->toBeFalse();
});
