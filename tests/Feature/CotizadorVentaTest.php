<?php

declare(strict_types=1);

use App\Models\Combo;
use App\Models\ComboEspecialItem;
use App\Models\Producto;
use App\Services\Pos\CotizadorVenta;

beforeEach(function () {
    $this->cotizador = app(CotizadorVenta::class);

    $this->pollo = Producto::factory()->proteina()->create([
        'nombre' => 'Pollo teriyaki', 'precio' => 60.00, 'tier_combo' => 'pollo_cerdo',
    ]);

    $this->arroz = Producto::factory()->create([
        'nombre' => 'Arroz imperial', 'categoria' => 'complemento', 'precio' => 30.00, 'grava_isv' => false,
    ]);
    $this->ensalada = Producto::factory()->create([
        'nombre' => 'Ensalada', 'categoria' => 'complemento', 'precio' => 30.00, 'grava_isv' => false,
    ]);
    $this->remolacha = Producto::factory()->create([
        'nombre' => 'Remolacha', 'categoria' => 'complemento', 'precio' => 30.00, 'grava_isv' => false,
    ]);
});

it('aplica el precio de combo cuando calza (pollo + 2 = L.100)', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 2, 'precio' => 100.00]);

    $linea = $this->cotizador->cotizarPlato($this->pollo->id, [$this->arroz->id, $this->ensalada->id]);

    expect($linea->precioUnitario)->toBe(100.00)   // combo, no 60+30+30=120
        ->and($linea->gravaIsv)->toBeFalse()
        ->and($linea->detalle)->toBe(['Arroz imperial', 'Ensalada']);
});

it('usa la suma individual cuando no calza ningún combo (pollo + 1 = L.90)', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 2, 'precio' => 100.00]);

    $linea = $this->cotizador->cotizarPlato($this->pollo->id, [$this->arroz->id]);

    expect($linea->precioUnitario)->toBe(90.00);   // 60 + 30, sin combo de 1
});

it('aplica el combo de 3 (pollo + 3 = L.125)', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 3, 'precio' => 125.00]);

    $linea = $this->cotizador->cotizarPlato(
        $this->pollo->id,
        [$this->arroz->id, $this->ensalada->id, $this->remolacha->id],
    );

    expect($linea->precioUnitario)->toBe(125.00)
        ->and($linea->detalle)->toHaveCount(3);
});

it('cobra el combo de 3 + extra por cada complemento adicional (4º en adelante)', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 3, 'precio' => 125.00]);
    $cuarto = Producto::factory()->create([
        'nombre' => 'Queso', 'categoria' => 'complemento', 'precio' => 30.00, 'grava_isv' => false,
    ]);

    // Pollo + 4 complementos = combo de 3 (125) + 1 extra (30) = 155, no 180.
    $linea = $this->cotizador->cotizarPlato(
        $this->pollo->id,
        [$this->arroz->id, $this->ensalada->id, $this->remolacha->id, $cuarto->id],
    );

    expect($linea->precioUnitario)->toBe(155.00)
        ->and($linea->detalle)->toHaveCount(4);
});

it('cotiza una bebida como línea propia que grava ISV', function () {
    $fresco = Producto::factory()->bebida()->create(['nombre' => 'Fresco', 'precio' => 25.00]);

    $linea = $this->cotizador->cotizarProducto($fresco->id);

    expect($linea->precioUnitario)->toBe(25.00)
        ->and($linea->gravaIsv)->toBeTrue();
});

it('cotiza un extra con su propio precio (exento por defecto)', function () {
    $extra = Producto::factory()->create([
        'nombre' => 'Doble carne', 'categoria' => 'extra', 'precio' => 40.00, 'grava_isv' => false,
    ]);

    $linea = $this->cotizador->cotizarProducto($extra->id);

    expect($linea->precioUnitario)->toBe(40.00)
        ->and($linea->gravaIsv)->toBeFalse();
});

it('captura el descuento del combo desde los precios de lista', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 2, 'precio' => 100.00]);

    $plato = $this->cotizador->cotizarPlato($this->pollo->id, [$this->arroz->id, $this->ensalada->id]);

    expect($plato->precioListaUnitario)->toBe(120.00)   // 60 + 30 + 30 à la carte
        ->and($plato->descuento())->toBe(20.00)         // 120 - 100 (combo)
        ->and($plato->componentes)->toHaveCount(3);     // proteína + 2 complementos

    $resumen = $this->cotizador->resumir([$plato]);

    expect($resumen->subtotalLista)->toBe(120.00)
        ->and($resumen->descuento)->toBe(20.00)
        ->and($resumen->total)->toBe(100.00);           // el ISV no cambia: sobre el neto cobrado
});

it('no aplica descuento cuando no calza ningún combo', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 2, 'precio' => 100.00]);

    // Pollo + 1 = 90 (suma individual, sin combo): lista == cobrado, sin descuento.
    $plato = $this->cotizador->cotizarPlato($this->pollo->id, [$this->arroz->id]);

    expect($plato->precioListaUnitario)->toBe(90.00)
        ->and($plato->descuento())->toBe(0.0);
});

it('prorratea el ISV de un platillo armado según el flag de cada producto', function () {
    // Platillo con precio fijo 100 (el flag del combo grava, pero manda el de cada producto).
    $platillo = Producto::factory()->create([
        'categoria' => 'combo', 'combo_modo' => 'platillo', 'nombre' => 'Desayuno', 'precio' => 100.00, 'grava_isv' => true,
    ]);
    $comida = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Frijoles', 'precio' => 30.00, 'grava_isv' => false]);
    $bebida = Producto::factory()->bebida()->create(['nombre' => 'Fresco', 'precio' => 20.00]); // grava ISV

    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $comida->id, 'cantidad' => 1, 'orden' => 1]);
    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $bebida->id, 'cantidad' => 1, 'orden' => 2]);

    $linea = $this->cotizador->cotizarProducto($platillo->id);
    $resumen = $this->cotizador->resumir([$linea]);

    // Lista: comida 30 (exento) + fresco 20 (grava) = 50. El precio fijo 100 se prorratea:
    // exento = 100 × 30/50 = 60; gravado neto = 40 → base 40/1.15 = 34.78, ISV 5.22.
    expect($resumen->exento)->toBe(60.00)
        ->and($resumen->gravado)->toBe(34.78)
        ->and($resumen->isv)->toBe(5.22)
        ->and($resumen->total)->toBe(100.00);
});

it('platillo personalizado: base a precio fijo + extras cobrados a su precio', function () {
    $platillo = Producto::factory()->create([
        'categoria' => 'combo', 'combo_modo' => 'platillo', 'nombre' => 'Combo', 'precio' => 150.00, 'grava_isv' => true,
    ]);
    $pollo = Producto::factory()->create(['categoria' => 'proteina', 'nombre' => 'Pollo', 'precio' => 60.00, 'grava_isv' => false]);
    $arroz = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Arroz', 'precio' => 30.00, 'grava_isv' => false]);
    $ensalada = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Ensalada', 'precio' => 30.00, 'grava_isv' => false]);

    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $pollo->id, 'cantidad' => 1, 'orden' => 1]);
    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $arroz->id, 'cantidad' => 1, 'orden' => 2]);
    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $ensalada->id, 'cantidad' => 1, 'orden' => 3]);

    // Base = 1 carne + 2 complementos. Selección: pollo + 3 complementos (1 extra).
    $sel = [
        ['producto_id' => $pollo->id, 'nombre' => 'Pollo', 'precio' => 60.0, 'grava_isv' => false, 'categoria' => 'proteina'],
        ['producto_id' => $arroz->id, 'nombre' => 'Arroz', 'precio' => 30.0, 'grava_isv' => false, 'categoria' => 'complemento'],
        ['producto_id' => $ensalada->id, 'nombre' => 'Ensalada', 'precio' => 30.0, 'grava_isv' => false, 'categoria' => 'complemento'],
        ['producto_id' => $arroz->id, 'nombre' => 'Arroz', 'precio' => 30.0, 'grava_isv' => false, 'categoria' => 'complemento'], // extra
    ];

    $lineas = $this->cotizador->cotizarPlatilloPersonalizado($platillo->id, $sel, 'sin sal');

    expect($lineas)->toHaveCount(2)                    // base + 1 extra
        ->and($lineas[0]->precioUnitario)->toBe(150.00) // base fijo (swaps no cambian precio)
        ->and($lineas[0]->nota)->toBe('sin sal')
        ->and($lineas[1]->nombre)->toContain('(extra)')
        ->and($lineas[1]->precioUnitario)->toBe(30.00); // extra a su precio

    $resumen = $this->cotizador->resumir($lineas);
    expect($resumen->total)->toBe(180.00)   // 150 base + 30 extra
        ->and($resumen->exento)->toBe(180.00);
});

it('arma el desglose fiscal de plato exento + bebida gravada', function () {
    Combo::factory()->create(['tier' => 'pollo_cerdo', 'complementos' => 2, 'precio' => 100.00]);
    $fresco = Producto::factory()->bebida()->create(['nombre' => 'Fresco', 'precio' => 23.00]);

    $plato = $this->cotizador->cotizarPlato($this->pollo->id, [$this->arroz->id, $this->ensalada->id]);
    $bebida = $this->cotizador->cotizarProducto($fresco->id);

    $resumen = $this->cotizador->resumir([$plato, $bebida]);

    expect($resumen->exento)->toBe(100.00)   // el plato combo
        ->and($resumen->gravado)->toBe(20.00) // 23 / 1.15
        ->and($resumen->isv)->toBe(3.00)
        ->and($resumen->total)->toBe(123.00);
});
