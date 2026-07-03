<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Services\Facturacion\FacturaPdfService;
use Illuminate\Http\Response;

/**
 * Sirve el PDF de una factura. La ruta es pública pero FIRMADA: el
 * cliente puede abrir/descargar la factura desde el link de WhatsApp sin
 * estar logueado, pero la URL no es adivinable ni enumerable.
 */
class FacturaPdfController extends Controller
{
    public function show(Factura $factura, FacturaPdfService $service): Response
    {
        $pdf = $service->pdf($factura);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura-'.$factura->numero.'.pdf"',
        ]);
    }

    /**
     * La misma factura como HTML directo, para la impresión de caja: sin
     * pasar por Chromium, la ventana de impresión sale al instante (el PDF
     * tarda ~3s en generarse y en caja eso traba la fila). El PDF queda
     * para WhatsApp/descarga, donde la espera no molesta.
     */
    public function ticket(Factura $factura, FacturaPdfService $service): Response
    {
        return response($service->html($factura), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
