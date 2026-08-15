<?php

declare(strict_types=1);

use App\Models\Producto;
use App\Services\Pos\MenuDiaService;
use Illuminate\Support\Facades\DB;

/**
 * El menú del POS se lee de caché y en arrays planos.
 *
 * El porqué está en MenuDiaService::paraElPos(): con el menú viviendo en
 * propiedades públicas de Livewire (arrays de modelos), cada toque de botón
 * en la caja disparaba una consulta por producto antes de hacer nada.
 */
it('devuelve el menú agrupado por sección y en arrays planos, no en modelos', function () {
    Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
    Producto::factory()->bebida()->create(['nombre' => 'Coca Cola', 'precio' => 25.00]);

    $menu = app(MenuDiaService::class)->paraElPos(now());

    expect($menu)->toHaveKeys(['proteinas', 'complementos', 'bebidas', 'extras', 'combos', 'platosDelDia'])
        ->and($menu['proteinas'])->toHaveCount(1)
        ->and($menu['bebidas'])->toHaveCount(1);

    // Arrays planos: si esto fuera un modelo, Livewire lo volvería a traer
    // de la base en cada request (que es justo lo que se quitó).
    expect($menu['proteinas'][0])->toBeArray()
        ->and($menu['proteinas'][0]['nombre'])->toBe('Pollo')
        ->and($menu['proteinas'][0]['precio'])->toBe(100.00);
});

it('la segunda lectura del día sale de caché, sin volver a consultar', function () {
    Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);

    app(MenuDiaService::class)->paraElPos(now());

    // Borrado por query builder: NO dispara eventos de modelo, así que la
    // caché queda intacta a propósito. Si el menú se volviera a consultar,
    // saldría vacío.
    DB::table('productos')->delete();

    expect(app(MenuDiaService::class)->paraElPos(now())['proteinas'])->toHaveCount(1);
});

it('tocar el catálogo tira la caché: el precio nuevo se ve en la caja', function () {
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);

    expect(app(MenuDiaService::class)->paraElPos(now())['proteinas'][0]['precio'])->toBe(100.00);

    // Guardar el producto dispara MenuPosObserver → se olvida el menú de hoy.
    $pollo->update(['precio' => 120.00]);

    expect(app(MenuDiaService::class)->paraElPos(now())['proteinas'][0]['precio'])->toBe(120.00);
});

it('un producto nuevo aparece en el menú sin esperar al otro día', function () {
    Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);

    expect(app(MenuDiaService::class)->paraElPos(now())['bebidas'])->toBeEmpty();

    Producto::factory()->bebida()->create(['nombre' => 'Mirinda Banana', 'precio' => 25.00]);

    expect(app(MenuDiaService::class)->paraElPos(now())['bebidas'])->toHaveCount(1);
});
