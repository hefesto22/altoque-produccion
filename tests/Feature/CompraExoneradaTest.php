<?php

declare(strict_types=1);

use App\Domain\Exceptions\VentaNoExonerableException;
use App\Domain\ValueObjects\ComponenteLinea;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Services\Fiscal\DeclaracionIsvService;
use App\Services\Pos\CalculadorVenta;
use App\Services\Pos\VentaService;

/**
 * COMPRA EXONERADA con Orden de Compra Exenta (OCE) del PAMEH.
 *
 * Marco legal aplicado acá:
 *  - Código Tributario art. 17 y art. 21 num. 10: la exoneración dispensa
 *    del pago del tributo; para organismos internacionales el mecanismo es
 *    la orden de compra que emite SEFIN.
 *  - Ley del ISV art. 7: al consumidor final el impuesto va INCLUIDO en el
 *    precio. Por eso un plato de L.100 es L.86.96 de producto + L.13.04 de
 *    impuesto, y el exonerado paga L.86.96.
 *  - Acuerdo 481-2017 art. 10 num. 8 y art. 11: la factura debe consignar el
 *    correlativo de la OCE y discriminar los valores exonerados.
 *  - Declaración mensual del ISV, casilla 130 "Ventas Exoneradas con OCE":
 *    se declara aparte y NO genera débito fiscal.
 *
 * La OCE es el interruptor: sin número no hay exoneración (art. 11 obliga a
 * consignarlo, así que una venta exonerada sin número no puede existir).
 */
function ventaExonerada(?string $orden = null, ?string $constancia = null, float $precio = 100.00, bool $grava = true): Factura
{
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['nombre' => 'Cena', 'precio' => $precio]);

    return app(VentaService::class)->registrarFactura(
        lineas: [new LineaVenta($producto->id, 'Cena', $precio, 1, gravaIsv: $grava)],
        cajeroId: $cajero->id,
        rtn: new RTN('08011985012345'),
        nombre: 'Programa Mundial de Alimentos',
        ordenCompraExenta: $orden,
        constanciaExonerado: $constancia,
    );
}

// ─────────────────── Captura del número ───────────────────

it('guarda en la factura los números de la compra exonerada', function () {
    $factura = ventaExonerada('OC2026186452', 'E2026001482');

    expect($factura->orden_compra_exenta)->toBe('OC2026186452')
        ->and($factura->constancia_exonerado)->toBe('E2026001482');
});

it('normaliza lo que se teclea en la caja: sin espacios y en mayúsculas', function () {
    expect(ventaExonerada('  oc2026186452  ')->orden_compra_exenta)->toBe('OC2026186452');
});

it('deja NULL cuando el campo viene vacío o con puros espacios', function () {
    // Cadena vacía sería peor que null: el ticket imprime "N/A" solo cuando
    // el dato NO existe, y una cadena vacía además exoneraría la venta.
    $factura = ventaExonerada('   ', '');

    expect($factura->orden_compra_exenta)->toBeNull()
        ->and($factura->constancia_exonerado)->toBeNull();
});

// ─────────────────── El impuesto ───────────────────

it('con orden de compra NO se cobra ISV y el cliente paga menos', function () {
    $factura = ventaExonerada('OC2026186452');

    expect((float) $factura->total)->toBe(86.96)      // L.100 − el ISV que la OCE dispensa
        ->and((float) $factura->exonerado)->toBe(86.96)
        ->and((float) $factura->isv)->toBe(0.00)
        ->and((float) $factura->gravado)->toBe(0.00); // no conviven gravado y exonerado
});

it('sin orden de compra la venta se cobra gravada como siempre', function () {
    $factura = ventaExonerada();

    expect((float) $factura->total)->toBe(100.00)
        ->and((float) $factura->gravado)->toBe(86.96)
        ->and((float) $factura->isv)->toBe(13.04)
        ->and((float) $factura->exonerado)->toBe(0.00);
});

it('lo exento sigue exento: la OCE no lo convierte en exonerado', function () {
    // Exento (el bien no causa ISV) y exonerado (el comprador está dispensado)
    // son cosas distintas y van en casillas distintas de la declaración.
    $factura = ventaExonerada('OC2026186452', precio: 50.00, grava: false);

    expect((float) $factura->exento)->toBe(50.00)
        ->and((float) $factura->exonerado)->toBe(0.00)
        ->and((float) $factura->total)->toBe(50.00);   // no hay impuesto que quitar
});

it('re-tarifa la línea y no solo los totales, para que el detalle cuadre', function () {
    // Si se tocaran nada más los totales, el ticket mostraría un plato de
    // L.100 sumando L.86.96.
    $factura = ventaExonerada('OC2026186452');
    $item = $factura->venta->items->first();

    expect((float) $item->precio_unitario)->toBe(86.96)
        ->and((float) $item->importe)->toBe(86.96)
        ->and(round((float) $factura->subtotal_lista - (float) $factura->descuento, 2))
        ->toEqualWithDelta((float) $factura->total, 0.005);
});

// ─────────────────── El papel ───────────────────

it('el ticket imprime el número de orden y el importe exonerado', function () {
    $factura = ventaExonerada('OC2026186452');

    $this->get($factura->urlTicket())
        ->assertOk()
        ->assertSee('OC2026186452')
        ->assertSee('No. orden compra exenta:')
        ->assertSee('Importe exonerado:');
});

it('sin orden de compra el ticket sigue saliendo con N/A', function () {
    $this->get(ventaExonerada()->urlTicket())->assertOk()->assertSee('N/A');
});

// ─────────────────── La declaración ───────────────────

it('la venta exonerada va a su propia casilla y no genera débito fiscal', function () {
    $factura = ventaExonerada('OC2026186452');
    $emitida = $factura->venta->vendida_at;

    $r = app(DeclaracionIsvService::class)->calcular($emitida->year, $emitida->month);

    expect($r->exonerado)->toBe(86.96)
        ->and($r->gravado)->toBe(0.0)
        ->and($r->isv)->toBe(0.0)     // sin débito: el ISV no se cobró
        ->and($r->exento)->toBe(0.0); // y NO se coló como exento
});

// ─────────────────── El cálculo, al centavo ───────────────────

it('el cálculo exonerado cuadra al centavo con combo, descuento y mezcla', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);

    $lineas = [
        new LineaVenta(1, 'Promo', 120.00, 2, true, [], 150.00, [
            new ComponenteLinea('Pollo', 90.00, true),
            new ComponenteLinea('Tortilla', 60.00, false),
        ]),
        new LineaVenta(2, 'Agua', 50.00, 1, false),
    ];

    $exo = $calc->calcular($calc->tarifarSinIsv($lineas), exonerada: true);

    // Los dos invariantes del bloque de totales del ticket.
    expect(round($exo->subtotalLista - $exo->descuento, 2))->toEqualWithDelta($exo->total, 0.005)
        ->and(round($exo->exento + $exo->exonerado + $exo->gravado + $exo->isv, 2))->toEqualWithDelta($exo->total, 0.005)
        ->and($exo->isv)->toBe(0.0);
});

it('no le quita el ISV dos veces a la misma línea', function () {
    $calc = new CalculadorVenta(tasaIsv: 0.15);
    $linea = [new LineaVenta(1, 'Cena', 100.00, 1, true)];

    // tarifarSinIsv ya deja la línea en neto; calcular() no debe volver a dividir.
    expect($calc->calcular($calc->tarifarSinIsv($linea), exonerada: true)->exonerado)->toBe(86.96);
});

// ─────────────────── Cuándo se puede exonerar ───────────────────
//
// REGLA DEL NEGOCIO (Mauricio, 2026-09-11): la orden de compra se aplica EN EL
// MOMENTO en que la venta se cobra y se imprime la factura. Ni antes ni
// después. Una venta ya facturada está hecha y no se re-tarifa.

it('un pedido pendiente SÍ se exonera al cobrarlo: ahí se emite la factura', function () {
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $producto = Producto::factory()->proteina()->create(['nombre' => 'Cena', 'precio' => 100.00]);

    // Todavía no hay documento fiscal: el pedido está esperando en caja.
    $venta = app(VentaService::class)->registrarPendiente(
        [new LineaVenta($producto->id, 'Cena', 100.00, 1, gravaIsv: true)],
        $cajero->id,
        'llevar',
    );

    expect((float) $venta->total)->toBe(100.00)
        ->and($venta->factura)->toBeNull();

    $factura = app(VentaService::class)->cobrarPendiente(
        venta: $venta,
        cajeroId: $cajero->id,
        rtn: new RTN('08011985012345'),
        nombre: 'Programa Mundial de Alimentos',
        ordenCompraExenta: 'OC2026186452',
    );

    // Se re-tarifó la línea, no solo los totales.
    expect((float) $factura->total)->toBe(86.96)
        ->and((float) $factura->exonerado)->toBe(86.96)
        ->and((float) $factura->isv)->toBe(0.00)
        ->and((float) $venta->fresh()->items->first()->precio_unitario)->toBe(86.96);
});

it('una venta YA facturada no se puede exonerar después', function () {
    // El camino para corregir una factura emitida es anular y volver a
    // facturar (documento nuevo, correlativo nuevo, impresión nueva), no
    // re-tarifar por detrás la que ya salió impresa.
    $factura = ventaExonerada();   // facturada normal, con su ISV

    expect(fn () => app(VentaService::class)->cobrarPendiente(
        venta: $factura->venta,
        cajeroId: $factura->venta->cajero_id,
        rtn: new RTN('08011985012345'),
        nombre: 'Programa Mundial de Alimentos',
        ordenCompraExenta: 'OC2026186452',
    ))->toThrow(VentaNoExonerableException::class);

    // Y la factura original quedó intacta: mismo total, mismo ISV.
    expect((float) $factura->fresh()->total)->toBe(100.00)
        ->and((float) $factura->fresh()->isv)->toBe(13.04)
        ->and((float) $factura->fresh()->exonerado)->toBe(0.00);
});
