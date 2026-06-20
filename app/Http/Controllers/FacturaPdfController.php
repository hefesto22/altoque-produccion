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
}
