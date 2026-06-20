<?php

declare(strict_types=1);

use App\Domain\Exceptions\FacturaNoAnulableException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\PeriodoFiscal;
use App\Models\Producto;
use App\Models\User;
use App\Services\Facturacion\FacturacionSarService;
use App\Services\Pos\VentaService;

function emitirFacturaPrueba(): Factura
{
    Cai::factory()->create();
    $cajero = User::factory()->create();
    $pollo = Producto::factory()->proteina()->create(['nombre' => 'Pollo', 'precio' => 120.00]);

    return app(VentaService::class)->registrarFactura(
        [new LineaVenta($pollo->id, 'Pollo', 120.00, 1, gravaIsv: false)],
        $cajero->id,
        new RTN('08011985012345'),
        'Cliente Prueba',
    );
}

it('genera un hash de verificación de 64 caracteres al emitir', function () {
    $factura = emitirFacturaPrueba();

    expect($factura->hash_verificacion)->not->toBeNull()
        ->and(strlen((string) $factura->hash_verificacion))->toBe(64);
});

it('permite anular dentro del plazo y registra el motivo', function () {
    $factura = emitirFacturaPrueba();
    $svc = app(FacturacionSarService::class);

    expect($svc->puedeAnular($factura))->toBeTrue();

    $svc->anular($factura, 'Error de digitación', $factura->venta->cajero_id);

    expect($factura->fresh()->anulada)->toBeTrue()
        ->and($factura->fresh()->motivo_anulacion)->toBe('Error de digitación');
});

it('no permite anular si el período ya fue declarado al SAR', function () {
    $factura = emitirFacturaPrueba();

    PeriodoFiscal::create([
        'anio'   => $factura->emitida_at->year,
        'mes'    => $factura->emitida_at->month,
        'estado' => 'declarado',
    ]);

    $svc = app(FacturacionSarService::class);

    expect($svc->puedeAnular($factura))->toBeFalse()
        ->and(fn () => $svc->anular($factura, 'tarde'))->toThrow(FacturaNoAnulableException::class);
});

it('no permite anular pasado el día límite del mes siguiente', function () {
    $factura = emitirFacturaPrueba();

    // Día 15 del mes siguiente (el límite por defecto es el 10).
    $referencia = $factura->emitida_at->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14);

    expect(app(FacturacionSarService::class)->puedeAnular($factura, $referencia))->toBeFalse();
});

it('no permite anular dos veces la misma factura', function () {
    $factura = emitirFacturaPrueba();
    $svc = app(FacturacionSarService::class);

    $svc->anular($factura, 'primera');

    expect(fn () => $svc->anular($factura->fresh(), 'segunda'))
        ->toThrow(FacturaNoAnulableException::class);
});
