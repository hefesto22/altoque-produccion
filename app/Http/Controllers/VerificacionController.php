<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmpresaSetting;
use App\Models\Factura;
use Illuminate\Contracts\View\View;

/**
 * Verificación pública de autenticidad de una factura. El cliente escanea
 * el QR (o abre el link) y ve la factura completa con sus validaciones
 * fiscales (CAI, rango, correlativo, fecha límite) para cotejarla campo
 * por campo contra el documento impreso. El hash es HMAC-SHA256 con la
 * clave del sistema: no se puede falsificar uno válido ni adivinar el de
 * otra factura.
 */
class VerificacionController extends Controller
{
    public function show(string $hash): View
    {
        $factura = Factura::query()
            ->with(['cai', 'venta.items'])
            ->where('hash_verificacion', $hash)
            ->first();

        $e = EmpresaSetting::actual();

        return view('verificacion', [
            'factura' => $factura,
            'empresa' => [
                'nombre'           => $e->nombreMostrar(),
                'razon_social'     => $e->razon_social,
                'nombre_comercial' => $e->nombre_comercial,
                'rtn'              => $e->rtn,
                'direccion'        => $e->direccion,
                'telefono'         => $e->telefono,
                'correo'           => $e->correo,
                'factura_concepto' => $e->factura_concepto,
            ],
            // Mismo criterio que el PDF: por factura si se definió; si no, default de la empresa.
            'detallada'    => $factura?->detallada ?? $e->factura_detallada,
            'verificadaAt' => now(),
        ]);
    }
}
