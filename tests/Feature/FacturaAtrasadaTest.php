<?php

declare(strict_types=1);

use App\Console\Commands\RegistrarFacturaAtrasada;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\User;
use App\Services\Caja\CorteCajaService;
use App\Services\Pos\VentaService;
use Illuminate\Support\Carbon;

/*
 * El caso real (2026-11-10): ayer a las 19:43 se cobraron L.285 en efectivo
 * a LDC y no se facturó. El turno cerró contando ese dinero, así que quedó
 * un sobrante de 285 que esta factura explica.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 07:00'));
    Cai::factory()->create();
    $this->cajero = User::factory()->create();
    $this->producto = Producto::factory()->proteina()->create(['precio' => 100]);
    $caja = app(CorteCajaService::class);
    $this->corte = $caja->abrir($this->cajero->id, 200.00);

    $this->travelTo(Carbon::parse('2026-11-09 19:34'));
    app(VentaService::class)->registrarFactura(
        [new LineaVenta($this->producto->id, 'Pollo', 100.00, 1, gravaIsv: true)],
        $this->cajero->id,
        new RTN('08011985012345'),
        'Cliente Uno',
        'efectivo',
    );

    // Fondo 200 + venta 100 = 300 esperado; se contaron 585 (con los 285 sin registrar).
    $this->travelTo(Carbon::parse('2026-11-09 21:00'));
    $caja->cerrar($this->corte, 585.00, 'Cierre normal');

    $this->travelTo(Carbon::parse('2026-11-10 07:52'));
});

/** @return array<string, string|bool> */
function opcionesFacturaLdc(bool $confirmar = true): array
{
    return [
        '--fecha'     => '2026-11-09 19:43',
        '--total'     => '285',
        '--rtn'       => '05019008175058',
        '--nombre'    => 'ldc',
        '--confirmar' => $confirmar,
    ];
}

it('emite la factura con su fecha real dentro del turno de esa hora y recalcula el arqueo', function () {
    $this->artisan('facturas:registrar-atrasada', opcionesFacturaLdc())->assertSuccessful();

    $factura = Factura::query()->latest('id')->firstOrFail();

    expect($factura->correlativo)->toBe(2)
        ->and($factura->emitida_at->format('Y-m-d H:i'))->toBe('2026-11-09 19:43')
        ->and($factura->detallada)->toBeFalse()
        ->and($factura->nombre_cliente)->toBe('LDC')
        ->and($factura->rtn_cliente)->toBe('05019008175058')
        ->and((float) $factura->gravado)->toBe(247.83)
        ->and((float) $factura->isv)->toBe(37.17)
        ->and((float) $factura->total)->toBe(285.00)
        ->and($factura->venta->corte_caja_id)->toBe($this->corte->id)
        ->and($factura->venta->cajero_id)->toBe($this->cajero->id)
        ->and($factura->venta->forma_pago)->toBe('efectivo')
        ->and($factura->venta->vendida_at->format('Y-m-d H:i'))->toBe('2026-11-09 19:43');

    $corte = $this->corte->fresh();

    expect($corte->cantidad_ventas)->toBe(2)
        ->and((float) $corte->total_ventas)->toBe(385.00)
        ->and((float) $corte->total_efectivo)->toBe(385.00)
        ->and((float) $corte->diferencia)->toBe(0.00)
        // El conteo y el cierre de la persona no se tocan.
        ->and((float) $corte->efectivo_contado)->toBe(585.00)
        ->and($corte->cerrado_at->format('Y-m-d H:i'))->toBe('2026-11-09 21:00')
        ->and($corte->notas)->toBe('Cierre normal');

    // El comando devuelve el reloj como lo encontró.
    expect(now()->format('Y-m-d H:i'))->toBe('2026-11-10 07:52');
});

it('sin --confirmar solo muestra lo que haría', function () {
    $this->artisan('facturas:registrar-atrasada', opcionesFacturaLdc(confirmar: false))
        ->expectsOutputToContain('000-001-01-00000002')
        ->assertSuccessful();

    expect(Factura::query()->count())->toBe(1)
        ->and(Producto::query()->where('nombre', RegistrarFacturaAtrasada::PRODUCTO)->exists())->toBeFalse()
        ->and((float) $this->corte->fresh()->diferencia)->toBe(285.00);
});

it('no emite nada si ya hay una factura posterior a esa hora', function () {
    $this->travelTo(Carbon::parse('2026-11-10 07:30'));
    app(CorteCajaService::class)->abrir($this->cajero->id, 0.0);
    app(VentaService::class)->registrarFactura(
        [new LineaVenta($this->producto->id, 'Pollo', 100.00, 1, gravaIsv: true)],
        $this->cajero->id,
        new RTN('08011985012345'),
        'Cliente Dos',
        'efectivo',
    );
    $this->travelTo(Carbon::parse('2026-11-10 07:52'));

    $this->artisan('facturas:registrar-atrasada', opcionesFacturaLdc())->assertFailed();

    expect(Factura::query()->count())->toBe(2)
        ->and((float) $this->corte->fresh()->diferencia)->toBe(285.00);
});

it('usa el nombre guardado si el RTN ya es un cliente registrado', function () {
    Cliente::registrar('05019008175058', 'LDC Servicios');

    $this->artisan('facturas:registrar-atrasada', opcionesFacturaLdc())->assertSuccessful();

    expect(Factura::query()->latest('id')->firstOrFail()->nombre_cliente)->toBe('LDC SERVICIOS');
});
