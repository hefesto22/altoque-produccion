<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Services\Facturacion\FacturaPdfService;
use Illuminate\Http\Request;
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
        // Solo bajo pedido: esto levanta Chromium y tarda ~3 s. Lo que se
        // comparte por WhatsApp es el HTML (instantáneo); el PDF queda para
        // quien de verdad quiera el archivo.
        $pdf = $service->pdf($factura);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura-'.$factura->numero.'.pdf"',
            // Es un documento fiscal inmutable: que el navegador no lo pida dos veces.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * La misma factura como HTML directo, para la impresión de caja: sin
     * pasar por Chromium, la ventana de impresión sale al instante (el PDF
     * tarda ~3s en generarse y en caja eso traba la fila). El PDF queda
     * para WhatsApp/descarga, donde la espera no molesta.
     */
    public function ticket(Request $request, Factura $factura, FacturaPdfService $service): Response
    {
        // ?cliente=1 (va dentro de la firma) = link de WhatsApp: se lee en
        // teléfono y ofrece descargar el PDF. Sin el flag es la impresión de
        // caja, que sale tal cual a la térmica.
        $paraCliente = $request->boolean('cliente');

        return response($service->html($factura, $paraCliente), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Factura + comanda en UN documento (dos páginas): una sola ventana de
     * impresión cuando la orden va a cocina (llevar/domicilio pagados de una).
     * Si la venta no tiene comanda, degrada al ticket de factura solo.
     */
    public function documentos(Factura $factura, FacturaPdfService $service): Response
    {
        $comanda = $factura->venta?->comanda;

        $html = $comanda !== null
            ? $service->htmlConComanda($factura, $comanda)
            : $service->html($factura);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
