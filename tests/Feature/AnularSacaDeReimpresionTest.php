<?php

declare(strict_types=1);

use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Cai;
use App\Models\Impresion;
use App\Models\Producto;
use App\Models\User;
use App\Services\Cocina\ComandaService;
use App\Services\Facturacion\FacturacionSarService;
use App\Services\Impresion\ColaImpresionService;
use App\Services\Pos\VentaService;
use Database\Seeders\RestauranteAccessSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Anular saca el papel de la cola — también el que YA se imprimió (2026-08-22).
 *
 * Lo que se protege acá: un pedido anulado no puede seguir ofrecido en
 * "Reimprimir… (últimas 2 h)". Si sigue ahí, la caja lo vuelve a sacar de un
 * toque y a cocina le entra un plato que nadie va a pagar.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $this->seed(RestauranteAccessSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador');
    $this->actingAs($this->admin);

    $this->pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 100.00]);
});

/** Registra un trabajo YA impreso: así se ve en "Reimprimir…". */
function papelImpreso(string $tipo, int $referenciaId, int $usuarioId): Impresion
{
    return Impresion::create([
        'tipo'          => $tipo,
        'referencia_id' => $referenciaId,
        'etiqueta'      => mb_strtoupper($tipo),
        'estado'        => 'impreso',
        'impreso_por'   => $usuarioId,
        'impreso_at'    => now(),
    ]);
}

/** Un pendiente con su comanda ya impresa, tal como queda al mandarlo a cocina. */
function pendienteYaImpreso(int $cajeroId, int $productoId): array
{
    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($productoId, 'Pollo', 100.00, 1, gravaIsv: false)],
        $cajeroId,
        'local',
    );

    $comanda = app(ComandaService::class)->crear($venta, 'local', [
        ['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => [], 'nota' => ''],
    ]);

    return [$venta, $comanda, papelImpreso('comanda', (int) $comanda->id, $cajeroId)];
}

it('anular un pendiente lo saca de "Reimprimir"', function () {
    [$venta, , $papel] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    expect(app(ColaImpresionService::class)->recientes())->toHaveCount(1);

    Livewire::test(PuntoDeVenta::class)
        ->call('pedirAnularPendiente', $venta->id)
        ->call('confirmarAnularPendiente');

    expect($venta->fresh()->anulada)->toBeTrue()
        ->and($papel->fresh()->estado)->toBe('cancelado')
        ->and(app(ColaImpresionService::class)->recientes())->toHaveCount(0)
        // Lo que de verdad pasó no se borra: ese ticket SÍ salió en su momento.
        ->and($papel->fresh()->impreso_at)->not->toBeNull();
});

it('un pedido con ampliación saca TODAS sus comandas de la cola', function () {
    [$venta, , $papelUno] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    // Lo que el cliente pidió DESPUÉS sobre la misma orden: segunda comanda.
    $ampliacion = app(ComandaService::class)->crear(
        $venta,
        'local',
        [['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => [], 'nota' => '']],
        esAmpliacion: true,
    );
    $papelDos = papelImpreso('comanda', (int) $ampliacion->id, (int) $this->admin->id);

    Livewire::test(PuntoDeVenta::class)
        ->call('pedirAnularPendiente', $venta->id)
        ->call('confirmarAnularPendiente');

    expect($papelUno->fresh()->estado)->toBe('cancelado')
        ->and($papelDos->fresh()->estado)->toBe('cancelado')
        ->and(app(ColaImpresionService::class)->recientes())->toHaveCount(0);
});

it('no toca el papel de otro pedido', function () {
    [$venta] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);
    [, , $papelAjeno] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    Livewire::test(PuntoDeVenta::class)
        ->call('pedirAnularPendiente', $venta->id)
        ->call('confirmarAnularPendiente');

    expect($papelAjeno->fresh()->estado)->toBe('impreso')
        ->and(app(ColaImpresionService::class)->recientes())->toHaveCount(1);
});

it('anular la factura saca su ticket, el combinado y la comanda de la venta', function () {
    Cai::factory()->create();

    $factura = app(VentaService::class)->registrarFactura(
        [new LineaVenta((int) $this->pollo->id, 'Pollo', 100.00, 1, gravaIsv: false)],
        (int) $this->admin->id,
        new RTN('08011985012345'),
        'Cliente Prueba',
    );

    $comanda = app(ComandaService::class)->crear($factura->venta, 'local', [
        ['nombre' => 'Pollo', 'cantidad' => 1, 'detalle' => [], 'nota' => ''],
    ]);

    $ticket = papelImpreso('factura', (int) $factura->id, (int) $this->admin->id);
    $combinado = papelImpreso('documentos', (int) $factura->id, (int) $this->admin->id);
    $papelCocina = papelImpreso('comanda', (int) $comanda->id, (int) $this->admin->id);

    app(FacturacionSarService::class)->anular($factura, 'Error de digitación', (int) $this->admin->id);

    expect($ticket->fresh()->estado)->toBe('cancelado')
        ->and($combinado->fresh()->estado)->toBe('cancelado')
        ->and($papelCocina->fresh()->estado)->toBe('cancelado')
        ->and(app(ColaImpresionService::class)->recientes())->toHaveCount(0);
});

it('descartar deja intacto lo que sigue pendiente de otras ventas', function () {
    [$venta, $comanda] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    // Un pendiente de la MISMA comanda (la caja aún no lo sacaba) también cae.
    $enEspera = Impresion::create([
        'tipo'     => 'comanda', 'referencia_id' => (int) $comanda->id,
        'etiqueta' => 'COMANDA', 'estado' => 'pendiente',
    ]);

    $descartados = app(ColaImpresionService::class)->descartarDeVenta($venta);

    expect($descartados)->toBe(2)
        ->and($enEspera->fresh()->estado)->toBe('cancelado')
        ->and(app(ColaImpresionService::class)->contarPendientes())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Limpieza retroactiva (impresiones:limpiar-anuladas)
|--------------------------------------------------------------------------
| Lo anulado ANTES de este cambio quedó con su ticket en "Reimprimir…".
*/

it('el comando arrastra el papel de lo que ya estaba anulado', function () {
    [$viejo, , $papelViejo] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    // Anulado "a la vieja": se marca la venta sin tocar la cola de impresión.
    $viejo->update(['anulada' => true, 'anulada_at' => now(), 'anulada_por' => $this->admin->id]);

    expect(app(ColaImpresionService::class)->recientes())->toHaveCount(1);

    $this->artisan('impresiones:limpiar-anuladas')->assertSuccessful();

    expect($papelViejo->fresh()->estado)->toBe('cancelado')
        ->and(app(ColaImpresionService::class)->recientes())->toHaveCount(0);
});

it('la limpieza no toca el papel de un pedido vivo y correrla dos veces no cambia nada', function () {
    [, , $papelVivo] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);
    [$anulado, , $papelAnulado] = pendienteYaImpreso((int) $this->admin->id, (int) $this->pollo->id);

    $anulado->update(['anulada' => true, 'anulada_at' => now(), 'anulada_por' => $this->admin->id]);

    $cola = app(ColaImpresionService::class);

    expect($cola->limpiarAnuladas())->toBe(1)
        // Idempotente: la segunda pasada ya no encuentra nada.
        ->and($cola->limpiarAnuladas())->toBe(0)
        ->and($papelAnulado->fresh()->estado)->toBe('cancelado')
        ->and($papelVivo->fresh()->estado)->toBe('impreso')
        ->and($cola->recientes())->toHaveCount(1);
});
