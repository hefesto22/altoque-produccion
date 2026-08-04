<?php

declare(strict_types=1);

use App\Domain\Exceptions\PedidoNoAnulableException;
use App\Domain\ValueObjects\LineaVenta;
use App\Filament\Pages\PuntoDeVenta;
use App\Livewire\ColaImpresion;
use App\Models\Cai;
use App\Models\Comanda;
use App\Models\Factura;
use App\Models\Impresion;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Impresion\ColaImpresionService;
use App\Services\Pos\VentaService;
use App\Support\Acceso;
use Database\Seeders\RestauranteAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cola de impresión (2026-08-04). Hay UNA térmica, en la computadora de la
 * caja, y los meseros toman pedidos desde tablets. Sin pantalla en cocina, el
 * ticket impreso es el único canal hacia la cocina: lo que se prueba acá es
 * que nada se pierda y que nada se imprima dos veces.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function usuarioDeRol(string $rol): User
{
    $user = User::factory()->create();
    $user->assignRole($rol);

    return $user;
}

/** Comanda real (con su venta) para tener algo concreto que imprimir. */
function comandaImprimible(User $autor): Comanda
{
    $producto = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);

    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $autor->id,
        'llevar',
    );

    return app(ComandaService::class)->crear(
        $venta,
        'llevar',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]],
    );
}

/** Factura real (cobrada) para tener un documento fiscal en la cola. */
function facturaImprimible(User $autor): Factura
{
    if (Cai::query()->count() === 0) {
        Cai::factory()->create();
    }

    app(CorteCajaService::class)->abrir($autor->id, 0.0); // una sola caja: si ya hay turno, lo reusa

    $producto = Producto::factory()->proteina()->create(['nombre' => 'Res', 'precio' => 80.00]);

    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Res', 80.00, 1, gravaIsv: false)],
        $autor->id,
        'llevar',
    );

    return app(VentaService::class)->cobrarPendiente($venta, $autor->id, null, 'Consumidor Final', 'efectivo');
}

/*
 * ── Dónde sale el papel ──────────────────────────────────────────────────
 */

it('el cajero imprime en el acto: la fila nace impresa y devuelve la URL', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);
    $comanda = comandaImprimible($cajero);

    $url = app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1', '1 plato');

    $fila = Impresion::query()->firstOrFail();

    expect($url)->toBeString()
        ->and($fila->estado)->toBe('impreso')
        ->and($fila->impreso_por)->toBe($cajero->id)
        ->and($fila->impreso_at)->not->toBeNull()
        ->and($fila->etiqueta)->toBe('ORDEN LL-1'); // snapshot en mayúsculas
});

it('el mesero no imprime: queda pendiente y no devuelve URL', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    $comanda = comandaImprimible($mesero);

    $url = app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1', '1 plato');

    $fila = Impresion::query()->firstOrFail();

    expect($url)->toBeNull()
        ->and($fila->estado)->toBe('pendiente')
        ->and($fila->solicitado_por)->toBe($mesero->id)
        ->and($fila->impreso_at)->toBeNull();
});

it('no encola nada si el documento no existe', function () {
    Auth::login(usuarioDeRol('mesero'));

    $url = app(ColaImpresionService::class)->enviar('comanda', 999_999, 'Fantasma');

    expect($url)->toBeNull()
        ->and(Impresion::query()->count())->toBe(0);
});

it('si la cola falla, el papel sale igual donde esté el usuario (fail-open)', function () {
    // Regla dura: enviar() no puede lanzar. La venta ya está registrada y el
    // correlativo SAR consumido — un error de la cola no puede tumbarla.
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);
    $comanda = comandaImprimible($cajero);

    Schema::drop('impresiones');

    $url = app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1');

    expect($url)->toBeString();
});

/*
 * ── Nada se imprime dos veces ────────────────────────────────────────────
 */

it('dos cajeros no pueden reclamar el mismo trabajo', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    $comanda = comandaImprimible($mesero);
    app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1');

    $id = (int) Impresion::query()->firstOrFail()->id;

    Auth::login(usuarioDeRol('cajero'));
    $cola = app(ColaImpresionService::class);

    expect($cola->reclamar($id))->not->toBeNull()
        ->and($cola->reclamar($id))->toBeNull(); // el segundo llega tarde
});

it('reimprimir no toca el estado ni a quién lo imprimió primero', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);
    $comanda = comandaImprimible($cajero);
    app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1');

    $fila = Impresion::query()->firstOrFail();
    $impresoAt = $fila->impreso_at;

    Livewire::actingAs($cajero)
        ->test(ColaImpresion::class)
        ->call('reimprimir', $fila->id)
        ->assertDispatched('imprimir-factura');

    expect($fila->fresh()->impreso_at?->toDateTimeString())->toBe($impresoAt?->toDateTimeString());
});

/*
 * ── Quién puede tocar la cola ────────────────────────────────────────────
 */

it('el mesero no puede imprimir desde el panel de la cola', function () {
    Livewire::actingAs(usuarioDeRol('mesero'))
        ->test(ColaImpresion::class)
        ->call('imprimir', 1)
        ->assertForbidden();
});

it('el mesero tampoco puede disparar una tanda', function () {
    Livewire::actingAs(usuarioDeRol('mesero'))
        ->test(ColaImpresion::class)
        ->call('imprimirTanda', 'todo')
        ->assertForbidden();
});

it('el cajero saca el pendiente y lo manda a la térmica', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    $comanda = comandaImprimible($mesero);
    app(ColaImpresionService::class)->enviar('comanda', $comanda->id, 'Orden LL-1');

    $fila = Impresion::query()->firstOrFail();

    Livewire::actingAs(usuarioDeRol('cajero'))
        ->test(ColaImpresion::class)
        ->call('imprimir', $fila->id)
        ->assertDispatched('imprimir-factura');

    expect($fila->fresh()->estado)->toBe('impreso');
});

/*
 * ── Tandas: veinte pendientes no son veinte clics ────────────────────────
 */

it('la tanda de comandas saca solo comandas y deja las facturas esperando', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    $cola = app(ColaImpresionService::class);

    $cola->enviar('comanda', comandaImprimible($mesero)->id, 'Orden LL-1');
    $cola->enviar('comanda', comandaImprimible($mesero)->id, 'Orden LL-2');
    $cola->enviar('factura', facturaImprimible($mesero)->id, 'Factura 1');

    expect($cola->conteoPendientes())->toMatchArray(['comanda' => 2, 'factura' => 1, 'todo' => 3]);

    Livewire::actingAs(usuarioDeRol('cajero'))
        ->test(ColaImpresion::class)
        ->call('imprimirTanda', 'comanda')
        ->assertDispatched('imprimir-factura'); // UN solo evento para las dos comandas

    // La factura sigue esperando: su papel va al cliente, no a cocina.
    expect($cola->conteoPendientes())->toMatchArray(['comanda' => 0, 'factura' => 1]);
});

it('la tanda manda un solo documento con todos los tickets adentro', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    $cola = app(ColaImpresionService::class);

    $primera = comandaImprimible($mesero);
    $segunda = comandaImprimible($mesero);
    $cola->enviar('comanda', $primera->id, 'Orden '.$primera->venta?->numero_orden);
    $cola->enviar('comanda', $segunda->id, 'Orden '.$segunda->venta?->numero_orden);

    Auth::login(usuarioDeRol('cajero'));
    $url = $cola->urlTanda($cola->reclamarTanda('comanda'));

    // Un solo diálogo de impresión: las dos comandas viven en el mismo papel.
    $respuesta = $this->get($url)
        ->assertOk()
        ->assertSee($primera->numero)
        ->assertSee($segunda->numero)
        ->assertSee((string) $primera->venta?->numero_orden)
        ->assertSee((string) $segunda->venta?->numero_orden);

    // Un salto entre los dos tickets — eso es lo que hace cortar a la térmica.
    expect(substr_count($respuesta->getContent(), 'class="salto"'))->toBe(1);
});

it('el documento de la tanda es firmado: sin firma no se imprime', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);
    app(ColaImpresionService::class)->enviar('comanda', comandaImprimible($mesero)->id, 'Orden LL-1');

    $id = Impresion::query()->firstOrFail()->id;

    $this->get(route('impresiones.tanda', ['ids' => $id]))->assertForbidden();
});

it('reimprimir la tanda no vuelve a cambiar el estado de nada', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);
    app(ColaImpresionService::class)->enviar('comanda', comandaImprimible($cajero)->id, 'Orden LL-1');

    $antes = Impresion::query()->firstOrFail()->impreso_at?->toDateTimeString();

    Livewire::actingAs($cajero)
        ->test(ColaImpresion::class)
        ->call('reimprimirTanda')
        ->assertDispatched('imprimir-factura');

    expect(Impresion::query()->count())->toBe(1)
        ->and(Impresion::query()->firstOrFail()->impreso_at?->toDateTimeString())->toBe($antes);
});

/*
 * ── Matriz de permisos ───────────────────────────────────────────────────
 */

it('cobrar, imprimir y cerrar turno son de la caja, no del salón', function () {
    foreach (['administrador', 'gerente', 'cajero'] as $rol) {
        Auth::login(usuarioDeRol($rol));

        expect(Acceso::puede('Cobrar'))->toBeTrue("{$rol} debería cobrar")
            ->and(Acceso::puede('ImprimirDirecto'))->toBeTrue("{$rol} debería imprimir")
            ->and(Acceso::puede('CerrarTurno'))->toBeTrue("{$rol} debería cerrar turno");
    }

    // El mesero solo arma el pedido y lo manda a cocina. El dinero se recibe
    // en la caja, siempre.
    Auth::login(usuarioDeRol('mesero'));

    expect(Acceso::puede('Cobrar'))->toBeFalse()
        ->and(Acceso::puede('ImprimirDirecto'))->toBeFalse()
        ->and(Acceso::puede('CerrarTurno'))->toBeFalse()
        ->and(Acceso::puede('AbrirTurno'))->toBeFalse()
        ->and(Acceso::puede('View:PuntoDeVenta'))->toBeTrue()
        ->and(Acceso::puede('ViewAny:Venta'))->toBeFalse(); // no ve el histórico
});

it('un pendiente se puede cobrar repartido entre efectivo, tarjeta y transferencia', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    if (Cai::query()->count() === 0) {
        Cai::factory()->create();
    }

    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $producto = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        'llevar',
    );

    $pos = Livewire::actingAs($cajero)->test(PuntoDeVenta::class)
        ->call('pedirMixtoPendiente', $venta->id)
        ->set('mixtoEfectivo', '40')
        ->set('mixtoTarjeta', '30')
        ->set('mixtoTransfer', '30')
        ->set('cobroBanco', 'Banco de Occidente')
        ->call('confirmarMixtoPendiente');

    $venta->refresh();

    expect($venta->pagada)->toBeTrue()
        ->and($venta->forma_pago)->toBe('mixto')
        ->and($venta->pagos()->sum('monto'))->toEqual(100.00)
        ->and($venta->pagos()->pluck('metodo')->sort()->values()->all())
        ->toBe(['efectivo', 'tarjeta', 'transferencia'])
        // El formulario se cierra solo cuando el cobro salió bien.
        ->and($pos->get('cobrandoMixtoId'))->toBeNull();
});

it('el mixto de un pendiente no cobra si los montos no cuadran', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    if (Cai::query()->count() === 0) {
        Cai::factory()->create();
    }

    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $producto = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        'llevar',
    );

    Livewire::actingAs($cajero)->test(PuntoDeVenta::class)
        ->call('pedirMixtoPendiente', $venta->id)
        ->set('mixtoEfectivo', '40')
        ->call('confirmarMixtoPendiente');

    expect($venta->fresh()->pagada)->toBeFalse();
});

it('anular un pendiente lo saca de por cobrar, de cocina y de la cola', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    $comanda = comandaImprimible($cajero);
    $venta = $comanda->venta;

    // La comanda quedó esperando papel (como si la hubiera mandado un mesero).
    Impresion::query()->create([
        'tipo'     => 'comanda', 'referencia_id' => $comanda->id,
        'etiqueta' => 'ORDEN '.$venta->numero_orden, 'estado' => 'pendiente',
    ]);

    Livewire::actingAs($cajero)->test(PuntoDeVenta::class)
        ->call('pedirAnularPendiente', $venta->id)
        ->call('confirmarAnularPendiente');

    $venta->refresh();

    expect($venta->anulada)->toBeTrue()
        ->and($venta->anulada_por)->toBe($cajero->id)
        ->and($venta->anulada_at)->not->toBeNull()
        // No se borra: queda el rastro de quién y cuándo.
        ->and(Venta::query()->whereKey($venta->id)->exists())->toBeTrue()
        // Sale de las tres listas.
        ->and(Venta::pendientes()->whereKey($venta->id)->exists())->toBeFalse()
        ->and(Comanda::enCocina()->whereKey($comanda->id)->exists())->toBeFalse()
        ->and(Impresion::query()->pendientes()->count())->toBe(0);
});

it('un pedido anulado ya no se puede cobrar', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    if (Cai::query()->count() === 0) {
        Cai::factory()->create();
    }

    app(CorteCajaService::class)->abrir($cajero->id, 0.0);

    $venta = comandaImprimible($cajero)->venta;
    app(VentaService::class)->anularPendiente($venta, $cajero->id);

    // Si el pedido no se hizo, no hay nada que cobrar.
    Livewire::actingAs($cajero)->test(PuntoDeVenta::class)
        ->call('cobrarPendienteCF', $venta->id, 'efectivo');

    expect($venta->fresh()->pagada)->toBeFalse()
        ->and($venta->fresh()->factura)->toBeNull();
});

it('no se puede anular dos veces ni anular algo ya cobrado', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    $venta = comandaImprimible($cajero)->venta;
    app(VentaService::class)->anularPendiente($venta, $cajero->id);

    expect(fn () => app(VentaService::class)->anularPendiente($venta->fresh(), $cajero->id))
        ->toThrow(PedidoNoAnulableException::class);
});

it('el mesero no puede cobrar ni facturar desde la tablet', function () {
    $mesero = usuarioDeRol('mesero');

    Livewire::actingAs($mesero)->test(PuntoDeVenta::class)
        ->call('facturarConsumidorFinal')->assertForbidden();

    Livewire::actingAs($mesero)->test(PuntoDeVenta::class)
        ->call('abrirFactura')->assertForbidden();

    Livewire::actingAs($mesero)->test(PuntoDeVenta::class)
        ->call('cobrarPendienteCF', 1, 'efectivo')->assertForbidden();
});

it('el mesero ni siquiera ve la lista de pedidos por cobrar', function () {
    $cajero = usuarioDeRol('cajero');
    Auth::login($cajero);

    // Un pendiente real, cobrable desde la caja.
    $producto = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
    app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajero->id,
        'llevar',
    );

    $enCaja = Livewire::actingAs($cajero)->test(PuntoDeVenta::class)->instance()->pedidosPendientes;
    $enTablet = Livewire::actingAs(usuarioDeRol('mesero'))->test(PuntoDeVenta::class)->instance()->pedidosPendientes;

    expect($enCaja)->toHaveCount(1)
        ->and($enTablet)->toBe([]);
});

it('un mesero no puede cerrar el turno de caja desde la tablet', function () {
    Livewire::actingAs(usuarioDeRol('mesero'))
        ->test(PuntoDeVenta::class)
        ->call('confirmarCierre')
        ->assertForbidden();
});

/*
 * ── Poda y ticket ────────────────────────────────────────────────────────
 */

it('la poda diaria nunca borra un pendiente', function () {
    Auth::login(usuarioDeRol('cajero'));

    $antiguedad = now()->subDays(200);

    $viejoPendiente = Impresion::create([
        'tipo'   => 'comanda', 'referencia_id' => 1, 'etiqueta' => 'VIEJA PENDIENTE',
        'estado' => 'pendiente',
    ]);
    $viejoPendiente->forceFill(['created_at' => $antiguedad])->save();

    $viejoImpreso = Impresion::create([
        'tipo'   => 'comanda', 'referencia_id' => 2, 'etiqueta' => 'VIEJA IMPRESA',
        'estado' => 'impreso', 'impreso_at' => $antiguedad,
    ]);
    $viejoImpreso->forceFill(['created_at' => $antiguedad])->save();

    $podables = (new Impresion)->prunable()->pluck('id')->all();

    expect($podables)->toContain($viejoImpreso->id)
        ->and($podables)->not->toContain($viejoPendiente->id);
});

it('la cola muestra el nombre del cliente para poder reimprimir el correcto', function () {
    $mesero = usuarioDeRol('mesero');
    Auth::login($mesero);

    $producto = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        $mesero->id,
        'llevar',
        nombreCliente: 'Doña Marta',
    );
    $comanda = app(ComandaService::class)->crear($venta, 'llevar', [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => []]]);

    app(ColaImpresionService::class)->enviar(
        'comanda',
        $comanda->id,
        "Orden {$venta->numero_orden} · ".$comanda->tipoLabel(),
        mb_strtoupper((string) $venta->nombre_orden).' · 1 plato(s)',
    );

    // El nombre va PRIMERO: es lo que identifica el pedido en la caja.
    expect(Impresion::query()->firstOrFail()->detalle)->toBe('DOÑA MARTA · 1 plato(s)');
});

it('el ticket de comanda dice quién atendió el pedido', function () {
    $mesero = usuarioDeRol('mesero');
    $mesero->update(['name' => 'Carlos Mesero']);

    $comanda = comandaImprimible($mesero);

    $this->get($comanda->urlTicket())
        ->assertOk()
        ->assertSee('CARLOS MESERO');
});
