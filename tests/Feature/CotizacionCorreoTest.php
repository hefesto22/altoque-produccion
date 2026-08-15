<?php

declare(strict_types=1);

use App\Models\Cotizacion;
use App\Models\EmpresaSetting;
use App\Services\Eventos\CotizacionPdfService;

/**
 * Dos correos distintos: el de la FACTURA pertenece al RTN del emisor
 * (personal de la titular) y el de la COTIZACIÓN es el comercial del negocio.
 * Confundirlos manda al cliente a escribirle al lugar equivocado.
 */

function cotizacionDePrueba(): Cotizacion
{
    return Cotizacion::create(['cliente_nombre' => 'INVERSIONES OLYMPO']);
}

it('la cotización usa el correo comercial, no el fiscal de las facturas', function () {
    EmpresaSetting::actual()->update([
        'correo'              => 'fiscal@hotmail.com',
        'correo_cotizaciones' => 'negocio@icloud.com',
    ]);

    $html = app(CotizacionPdfService::class)->html(cotizacionDePrueba());

    expect($html)->toContain('negocio@icloud.com')
        ->and($html)->not->toContain('fiscal@hotmail.com');
});

it('sin correo comercial cargado, la cotización cae al fiscal', function () {
    EmpresaSetting::actual()->update([
        'correo'              => 'fiscal@hotmail.com',
        'correo_cotizaciones' => null,
    ]);

    expect(app(CotizacionPdfService::class)->html(cotizacionDePrueba()))
        ->toContain('fiscal@hotmail.com');
});
