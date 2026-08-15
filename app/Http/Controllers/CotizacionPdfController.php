<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Services\Eventos\CotizacionPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sirve la cotización de evento. Las rutas son públicas pero FIRMADAS: el
 * cliente la abre desde el link de WhatsApp sin estar logueado, pero la URL
 * no es adivinable.
 */
class CotizacionPdfController extends Controller
{
    /**
     * PDF armado en el servidor. Levanta Chromium (~3 s) y ya NADIE lo
     * enlaza: lo que se comparte es el HTML de ver(). Queda como endpoint por
     * si alguna vez hace falta el archivo generado del lado del servidor.
     */
    public function show(Cotizacion $cotizacion, CotizacionPdfService $service): Response
    {
        $pdf = $service->pdf($cotizacion);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="cotizacion-'.$cotizacion->numero.'.pdf"',
        ]);
    }

    /**
     * La misma cotización como HTML directo: abre al instante porque no pasa
     * por Chromium. Es lo que va por WhatsApp y lo que ve el personal. Guardar
     * el archivo lo resuelve el diálogo de impresión del propio teléfono
     * ("Guardar como PDF"): el servidor no genera ni almacena nada.
     */
    public function ver(Request $request, Cotizacion $cotizacion, CotizacionPdfService $service): Response
    {
        // ?cliente=1 (va DENTRO de la firma) = vista pública para el teléfono.
        // Sin el flag es el documento carta tal cual.
        return response($service->html($cotizacion, $request->boolean('cliente')), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
