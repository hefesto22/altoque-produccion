<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\ComboEspecialItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Pos\VentaService;
use Database\Seeders\RestauranteAccessSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nota por línea del carrito (2026-08-22).
 *
 * Lo que se protege acá: la indicación del cliente ("sin cebolla") se puede
 * poner sobre CUALQUIER línea —no solo sobre el platillo armado— y llega
 * entera hasta la cocina y hasta `venta_items.nota`. Una nota que se pierde
 * en el camino es un plato devuelto.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $this->actingAs($admin);

    $this->baleada = Producto::factory()->create([
        'categoria' => 'extra', 'nombre' => 'Baleada con pollo', 'precio' => 30.00, 'grava_isv' => true,
    ]);
});

it('le pone una nota a un producto suelto del carrito', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->baleada->id);

    $key = (string) $pos->get('carrito')[0]['key'];

    $pos->call('abrirNota', $key);

    expect($pos->get('notando'))->toBeTrue()
        ->and($pos->get('notaNombre'))->toBe('Baleada con pollo');

    $pos->call('guardarNota', 'sin cebolla');

    expect($pos->get('carrito')[0]['nota'])->toBe('sin cebolla')
        ->and($pos->get('notando'))->toBeFalse()
        ->and($pos->get('notaKey'))->toBe('');
});

it('normaliza la nota: sin espacios de más y con tope de 120', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->baleada->id);

    $key = (string) $pos->get('carrito')[0]['key'];

    $pos->call('abrirNota', $key)
        ->call('guardarNota', "  sin   cebolla\n y sin chile  ");

    expect($pos->get('carrito')[0]['nota'])->toBe('sin cebolla y sin chile');

    // La comanda es de 80 mm: la nota no puede empujar el resto del ticket.
    $pos->call('abrirNota', $key)
        ->call('guardarNota', str_repeat('a', 200));

    expect(mb_strlen((string) $pos->get('carrito')[0]['nota']))->toBe(120);
});

it('guardar la nota vacía la quita de la línea', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->baleada->id);

    $key = (string) $pos->get('carrito')[0]['key'];

    $pos->call('abrirNota', $key)->call('guardarNota', 'sin cebolla')
        ->call('abrirNota', $key)->call('guardarNota', '');

    expect($pos->get('carrito')[0]['nota'])->toBe('');
});

it('un producto CON nota no se fusiona: queda una línea aparte', function () {
    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('agregarProducto', $this->baleada->id);

    $key = (string) $pos->get('carrito')[0]['key'];

    $pos->call('abrirNota', $key)->call('guardarNota', 'sin cebolla');

    // Otra baleada, esta vez normal: son dos cosas distintas para la cocina.
    $pos->call('agregarProducto', $this->baleada->id);

    $carrito = $pos->get('carrito');

    expect($carrito)->toHaveCount(2)
        ->and($carrito[0]['nota'])->toBe('sin cebolla')
        ->and((int) $carrito[0]['cantidad'])->toBe(1)
        ->and($carrito[1]['nota'])->toBe('');

    // La segunda (sin nota) sí sigue acumulando.
    $pos->call('agregarProducto', $this->baleada->id);

    expect($pos->get('carrito'))->toHaveCount(2)
        ->and((int) $pos->get('carrito')[1]['cantidad'])->toBe(2);
});

it('la nota puesta desde el carrito se precarga al editar el platillo', function () {
    $platillo = Producto::factory()->create([
        'categoria' => 'combo', 'combo_modo' => 'platillo',
        'nombre'    => 'Plato ejecutivo', 'precio' => 150.00, 'grava_isv' => true,
    ]);
    $pollo = Producto::factory()->create(['categoria' => 'proteina', 'nombre' => 'Pollo', 'precio' => 60.00, 'grava_isv' => false]);
    ComboEspecialItem::create(['combo_id' => $platillo->id, 'producto_id' => $pollo->id, 'cantidad' => 1, 'orden' => 1]);

    $pos = Livewire::test(PuntoDeVenta::class)
        ->call('personalizarPlatillo', $platillo->id)
        ->call('confirmarPlatillo');

    $linea = $pos->get('carrito')[0];

    $pos->call('abrirNota', (string) $linea['key'])->call('guardarNota', 'bien cocido');

    // El modal grande no puede contradecir a la nota rápida: trae la misma.
    $pos->call('editarPlatillo', (string) $linea['grupo']);

    expect($pos->get('platilloNota'))->toBe('bien cocido');

    $pos->call('confirmarPlatillo');

    expect($pos->get('carrito'))->toHaveCount(1)
        ->and($pos->get('carrito')[0]['nota'])->toBe('bien cocido');
});

it('la nota de la línea queda guardada en venta_items', function () {
    $cajero = User::factory()->create();

    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($this->baleada->id, 'Baleada con pollo', 30.00, 1, gravaIsv: true, nota: 'sin cebolla')],
        $cajero->id,
        'llevar',
    );

    expect($venta->items()->first()?->nota)->toBe('sin cebolla');
});
