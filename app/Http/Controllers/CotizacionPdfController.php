<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Services\Eventos\CotizacionPdfService;
use Illuminate\Http\Response;

/**
 * Sirve el PDF de una cotización de evento. La ruta es pública pero
 * FIRMADA: el cliente puede abrir/descargar la cotización desde el link
 * de WhatsApp sin estar logueado, pero la URL no es adivinable.
 */
class CotizacionPdfController extends Controller
{
    public function show(Cotizacion $cotizacion, CotizacionPdfService $service): Response
    {
        $pdf = $service->pdf($cotizacion);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="cotizacion-'.$cotizacion->numero.'.pdf"',
        ]);
    }
}
