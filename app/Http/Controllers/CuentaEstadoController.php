<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\CuentaPrepago;
use App\Models\EmpresaSetting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Estado de cuenta que ve el CLIENTE de una cuenta prepago.
 *
 * Ruta pública pero FIRMADA y por token: el cliente la abre desde su WhatsApp
 * sin estar logueado, y la URL no es adivinable ni deja pasar de una cuenta a
 * otra cambiando un número.
 *
 * HTML, no PDF — mismo criterio que factura y cotización: abre al instante en
 * el teléfono y el servidor no genera ni guarda ningún archivo.
 */
class CuentaEstadoController extends Controller
{
    /** Cuántos movimientos se muestran. El saldo y los totales salen de TODOS. */
    private const MAX_MOVIMIENTOS = 60;

    public function estado(string $token): Response
    {
        $cuenta = CuentaPrepago::query()->where('token', $token)->firstOrFail();

        $movimientos = $cuenta->movimientos()
            ->with(['venta.factura:id,venta_id,numero'])
            ->limit(self::MAX_MOVIMIENTOS)
            ->get();

        $e = EmpresaSetting::actual();

        return response(view('cuentas.estado', [
            'cuenta'      => $cuenta,
            'movimientos' => $movimientos,
            'tope'        => self::MAX_MOVIMIENTOS,
            'logo'        => $this->logoDataUri(),
            'empresa'     => [
                'nombre'    => $e->nombreMostrar(),
                'direccion' => $e->direccion,
                'telefono'  => $e->telefono,
                'correo'    => $e->correo,
            ],
        ])->render(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Logo embebido: el estado de cuenta se abre en teléfonos con conexión
     * mala y una imagen enlazada que no carga deja la página coja.
     */
    private function logoDataUri(): ?string
    {
        $path = BrandingSetting::current()->logo_path;

        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg'         => 'image/svg+xml',
            default       => 'image/webp',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }
}
