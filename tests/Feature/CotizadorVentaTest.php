<?php

declare(strict_types=1);

use App\Models\Combo;
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
