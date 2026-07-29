<?php

declare(strict_types=1);

use App\Models\ComboEspecial;
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

    $menuDia = app(MenuDiaService::class);

    foreach (Servicio::query()->pluck('id') as $servicioId) {
        expect($menuDia->disponibles(Carbon::today(), (int) $servicioId)->pluck('nombre'))
            ->toContain('Sopa de caracol');
    }

    expect($plato->categoria)->toBe('combo')
        ->and($plato->combo_modo)->toBe('platillo')
        ->and($plato->fecha_especial->toDateString())->toBe(Carbon::today()->toDateString())
        ->and($plato->items()->count())->toBe(2);
});

it('crear el plato del día NO tumba el resto del menú', function () {
    serviciosDelLocal();
    Producto::factory()->count(3)->create();

    app(PlatoDelDiaService::class)->crear(Carbon::today(), 'Especial de hoy', 150.00, []);

    $menuDia = app(MenuDiaService::class);
    $almuerzo = Servicio::query()->where('slug', 'almuerzo')->firstOrFail();

    // Nadie armó el menú del día: sigue valiendo la tolerancia de mostrar
    // todo el catálogo. Publicar el especial no puede romper eso.
    expect($menuDia->hayMenuCargado(Carbon::today()))->toBeFalse()
        ->and($menuDia->disponibles(Carbon::today(), $almuerzo->id))->toHaveCount(4);
});

it('el plato del día aparece aunque el servicio tenga su propio menú armado', function () {
    serviciosDelLocal();
    $almuerzo = Servicio::query()->where('slug', 'almuerzo')->firstOrFail();
    $pollo = Producto::factory()->proteina()->create();

    $menuDia = app(MenuDiaService::class);
    $menuDia->sincronizar(Carbon::today(), $almuerzo->id, [$pollo->id]);

    app(PlatoDelDiaService::class)->crear(Carbon::today(), 'Especial de hoy', 150.00, []);

    expect($menuDia->disponibles(Carbon::today(), $almuerzo->id)->pluck('nombre'))
        ->toContain('Especial de hoy')
        ->toContain($pollo->nombre);
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
        ->and(ComboEspecial::query()->find($plato->id))->toBeNull();
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
        ->and(ComboEspecial::query()->find($plato->id)->activo)->toBeFalse();

    // Y desactivado ya no sale en el menú de su propio día.
    expect(app(MenuDiaService::class)->disponibles(Carbon::today(), null)->pluck('nombre'))
        ->not->toContain('Especial vendido');
});
