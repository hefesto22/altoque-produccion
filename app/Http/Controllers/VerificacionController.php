<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Factura;
use Illuminate\Contracts\View\View;

/**
 * Verificación pública de autenticidad de una factura. El cliente escanea
 * el QR (o abre el link) y ve si la factura es válida y vigente. El hash
 * es HMAC-SHA256 con la clave del sistema: no se puede falsificar uno
 * válido ni adivinar el de otra factura.
 */
class VerificacionController extends Controller
{
    public function show(string $hash): View
    {
        $factura = Factura::query()
            ->with('cai')
            ->where('hash_verificacion', $hash)
            ->first();

        return view('verificacion', ['factura' => $factura]);
    }
}
