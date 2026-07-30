<?php

declare(strict_types=1);

use App\Filament\Pages\PuntoDeVenta;
use App\Models\ComboEspecialItem;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RestauranteAccessSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Editar un platillo que YA está en el carrito (2026-07-30).
 *
 * Lo que se protege acá: al guardar cambios la línea se REEMPLAZA, nunca se
 * duplica — un platillo cobrado dos veces es plata mal cobrada y un desglose
 * de ISV que no cuadra.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $this->actingAs($admin);

    $this->platillo = Producto::factory()->create([
        'categoria' => 'combo', 'combo_modo' => 'platillo',
        'nombre'    => 'Plato ejecutivo', 'precio' => 150.00, 'grava_isv' => true,
    ]);
    $this->pollo = Producto::factory()->create(['categoria' => 'proteina', 'nombre' => 'Pollo', 'precio' => 60.00, 'grava_isv' => false]);
    $this->arroz = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Arroz', 'precio' => 30.00, 'grava_isv' => false]);
    $this->ensalada = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Ensalada', 'precio' => 30.00, 'grava_isv' => false]);
    $this->tajadas = Producto::factory()->create(['categoria' => 'complemento', 'nombre' => 'Tajadas', 'precio' => 30.00, 'grava_isv' => false]);

    // Base del platillo: 1 carne + 2 complementos.
    ComboEspecialItem::create(['combo_id' => $this->platillo->id, 'producto_id' => $this->pollo->id, 'cantidad' => 1, 'orden' => 1]);
    ComboEspecialItem::create(['combo_id' => $this->platillo->id, 'producto_id' => $this->arroz->id, 'cantidad' => 1, 'orden' => 2]);
    ComboEspecialItem::create(['combo_id' => $this->platillo->id, 'producto_id' => $this->ensalada->id, 'cantidad' => 1, 'orden' => 3]);
});

it('editar un platillo del carrito lo reemplaza, no lo duplica', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('personalizarPlatillo', $this->platillo->id)
        ->call('confirmarPlatillo');

    $carrito = $pos->get('carrito');
    expect($carrito)->toHaveCount(1);

    $grupo = (string) $carrito[0]['grupo'];

    // Reabre el modal con lo que ya tenía: pollo + arroz + ensalada.
    $pos->call('editarPlatillo', $grupo);

    expect($pos->get('platilloEditGrupo'))->toBe($grupo)
        ->and($pos->get('platilloSel'))->toHaveCount(3);

    // Cambia la ensalada por tajadas y guarda.
    $pos->call('platilloQuitar', 2)
        ->call('platilloAgregar', $this->tajadas->id)
        ->call('confirmarPlatillo');

    $carrito = $pos->get('carrito');

    expect($carrito)->toHaveCount(1)
        ->and($carrito[0]['detalle'])->toContain('Tajadas')
        ->and($carrito[0]['detalle'])->not->toContain('Ensalada')
        ->and($pos->get('platilloEditGrupo'))->toBeNull()
        ->and($pos->get('personalizando'))->toBeFalse();
});

it('editar conserva la cantidad que ya tenía la línea', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('personalizarPlatillo', $this->platillo->id)
        ->call('confirmarPlatillo');

    $key = (string) $pos->get('carrito')[0]['key'];
    $grupo = (string) $pos->get('carrito')[0]['grupo'];

    $pos->call('cambiarCantidad', $key, 2);   // ahora son 3 iguales
    expect($pos->get('carrito')[0]['cantidad'])->toBe(3);

    $pos->call('editarPlatillo', $grupo)
        ->call('platilloQuitar', 2)
        ->call('platilloAgregar', $this->tajadas->id)
        ->call('confirmarPlatillo');

    expect($pos->get('carrito'))->toHaveCount(1)
        ->and($pos->get('carrito')[0]['cantidad'])->toBe(3);
});

it('al editar, los extras del platillo se rehacen sin quedar sueltos', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('personalizarPlatillo', $this->platillo->id)
        ->call('platilloAgregar', $this->tajadas->id)   // 3er complemento = extra
        ->call('confirmarPlatillo');

    expect($pos->get('carrito'))->toHaveCount(2);   // base + extra

    $grupo = (string) $pos->get('carrito')[0]['grupo'];

    // Al editar se quita el extra: el carrito vuelve a una sola línea.
    $pos->call('editarPlatillo', $grupo)
        ->call('platilloQuitar', 3)
        ->call('confirmarPlatillo');

    $carrito = $pos->get('carrito');

    expect($carrito)->toHaveCount(1)
        ->and($carrito[0]['tipo'])->toBe('plato');
});

it('un producto suelto del carrito no se puede editar como platillo', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->arroz->id);

    $grupo = (string) $pos->get('carrito')[0]['grupo'];

    $pos->call('editarPlatillo', $grupo);

    expect($pos->get('personalizando'))->toBeFalse()
        ->and($pos->get('carrito'))->toHaveCount(1);
});
