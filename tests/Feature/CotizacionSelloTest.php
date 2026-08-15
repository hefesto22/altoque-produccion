<?php

declare(strict_types=1);

use App\Models\BrandingSetting;
use App\Models\Cotizacion;
use App\Services\Eventos\CotizacionPdfService;
use Illuminate\Support\Facades\Storage;

/**
 * El sello de hule del negocio en la cotización que ve el cliente.
 * Se sube una sola vez en Configuración y sale frente a los totales.
 */

it('la cotización sale igual que siempre si no hay sello cargado', function () {
    $cotizacion = Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);

    expect(app(CotizacionPdfService::class)->html($cotizacion))
        ->not->toContain('class="sello"');
});

it('embebe el sello cargado en Configuración, no lo enlaza', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/sello.webp', 'imagen-de-prueba');

    BrandingSetting::current()->update(['sello_path' => 'branding/sello.webp']);

    $cotizacion = Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);
    $html = app(CotizacionPdfService::class)->html($cotizacion);

    // Data URI y no una URL: Browsershot arma el PDF sin sesión, y el link
    // que abre el cliente tiene que verse completo con mala conexión.
    expect($html)->toContain('class="sello"')
        ->and($html)->toContain('data:image/webp;base64,'.base64_encode('imagen-de-prueba'));
});
