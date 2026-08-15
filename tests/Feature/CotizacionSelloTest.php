<?php

declare(strict_types=1);

use App\Models\BrandingSetting;
use App\Models\Cotizacion;
use App\Services\Eventos\CotizacionPdfService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * El sello de hule del negocio en la cotización que ve el cliente.
 * Se sube una sola vez en Configuración y sale frente a los totales.
 *
 * OJO con el arreglo: `BrandingSetting::current()` es un singleton CACHEADO.
 * Actualizarlo a través de esa instancia deja la caché y la base desalineadas
 * según el orden en que corran los tests, así que acá se toca la fila directo
 * y se limpia la caché a mano.
 */
function brandingConSello(?string $path): void
{
    // El flush va PRIMERO: el singleton cacheado sobrevive de un test a otro,
    // así que sin esto current() daba un cache hit, no creaba la fila, y el
    // update de abajo no tocaba nada (la base quedaba vacía).
    Cache::flush();

    BrandingSetting::current();                                  // ahora sí garantiza la fila
    BrandingSetting::query()->update(['sello_path' => $path]);   // sin pasar por la caché

    Cache::flush();                                              // que el servicio relea de la base
}

it('la cotización sale igual que siempre si no hay sello cargado', function () {
    brandingConSello(null);

    $cotizacion = Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);

    expect(app(CotizacionPdfService::class)->html($cotizacion))
        ->not->toContain('class="sello"');
});

it('embebe el sello cargado en Configuración, no lo enlaza', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/sello.webp', 'imagen-de-prueba');

    brandingConSello('branding/sello.webp');

    // Guarda del arreglo: si esto falla, el problema no está en la vista.
    expect(BrandingSetting::current()->sello_path)->toBe('branding/sello.webp');

    $cotizacion = Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);
    $html = app(CotizacionPdfService::class)->html($cotizacion);

    // Data URI y no una URL: Browsershot arma el PDF sin sesión, y el link
    // que abre el cliente tiene que verse completo con mala conexión.
    expect($html)->toContain('class="sello"')
        ->and($html)->toContain('data:image/webp;base64,'.base64_encode('imagen-de-prueba'));
});
