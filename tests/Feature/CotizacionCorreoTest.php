<?php

declare(strict_types=1);

use App\Models\Cotizacion;
use App\Models\EmpresaSetting;
use App\Services\Eventos\CotizacionPdfService;
use Illuminate\Support\Facades\Cache;

/**
 * Dos correos distintos: el de la FACTURA pertenece al RTN del emisor
 * (personal de la titular) y el de la COTIZACIÓN es el comercial del negocio.
 * Confundirlos manda al cliente a escribirle al lugar equivocado.
 */

function cotizacionDePrueba(): Cotizacion
{
    return Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);
}

/**
 * `EmpresaSetting::actual()` es un singleton cacheado: se toca la fila directo
 * y se limpia la caché para que el test no dependa del orden de ejecución.
 */
function empresaConCorreos(string $fiscal, ?string $cotizaciones): void
{
    EmpresaSetting::actual();
    EmpresaSetting::query()->update(['correo' => $fiscal, 'correo_cotizaciones' => $cotizaciones]);
    Cache::flush();
}

it('la cotización usa el correo comercial, no el fiscal de las facturas', function () {
    empresaConCorreos('fiscal@hotmail.com', 'negocio@icloud.com');

    $html = app(CotizacionPdfService::class)->html(cotizacionDePrueba());

    expect($html)->toContain('negocio@icloud.com')
        ->and($html)->not->toContain('fiscal@hotmail.com');
});

it('sin correo comercial cargado, la cotización cae al fiscal', function () {
    empresaConCorreos('fiscal@hotmail.com', null);

    expect(app(CotizacionPdfService::class)->html(cotizacionDePrueba()))
        ->toContain('fiscal@hotmail.com');
});
