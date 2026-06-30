<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Models\BrandingSetting;
use App\Models\EmpresaSetting;
use App\Models\Factura;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

/**
 * Genera el PDF de la factura SAR en formato 80mm (térmica). El mismo
 * archivo sirve para imprimir en la térmica y para compartir como
 * descarga (link firmado por WhatsApp).
 *
 * Reimpresión idempotente: renderiza la factura existente, nunca
 * consume un nuevo correlativo.
 */
final class FacturaPdfService
{
    public function __construct(private readonly QrService $qr) {}

    public function html(Factura $factura): string
    {
        $factura->loadMissing(['venta.items', 'cai']);

        $e = EmpresaSetting::actual();

        $empresa = [
            'nombre'           => $e->nombreMostrar(),
            'razon_social'     => $e->razon_social,
            'nombre_comercial' => $e->nombre_comercial,
            'rtn'              => $e->rtn,
            'direccion'        => $e->direccion,
            'telefono'         => $e->telefono,
            'correo'           => $e->correo,
            'factura_concepto' => $e->factura_concepto,
        ];

        return view('pdf.factura', [
            'f'       => $factura,
            'empresa' => $empresa,
            'logo'    => $this->logoDataUri(),
            // Por factura si se definió; si no, el default de la empresa.
            'detallada' => $factura->detallada ?? $e->factura_detallada,
            'qr'        => $factura->hash_verificacion !== null
                ? $this->qr->dataUri($factura->urlVerificacion(), 150)
                : null,
        ])->render();
    }

    /** Logo de la empresa como data URI (se embebe en el PDF, sin red). */
    private function logoDataUri(): ?string
    {
        $path = BrandingSetting::current()->logo_path;

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg'         => 'image/svg+xml',
            default       => 'image/webp',
        };

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }

    /** Bytes del PDF (80mm de ancho). */
    public function pdf(Factura $factura): string
    {
        $shot = Browsershot::html($this->html($factura))
            ->paperSize(80, 250, 'mm')
            ->margins(3, 3, 3, 3)
            ->showBackground()
            // Chromium corre bajo www-data sin namespaces de usuario: el sandbox
            // no puede levantar su proceso y crashea (crashpad). --no-sandbox lo
            // resuelve. --disable-dev-shm-usage evita crashes por /dev/shm chico
            // en el contenedor del VPS bajo alto flujo de impresión.
            ->noSandbox()
            ->addChromiumArguments(['disable-dev-shm-usage']);

        if ($node = config('pdf.node_path')) {
            $shot->setNodeBinary($node);
        }

        if ($npm = config('pdf.npm_path')) {
            $shot->setNpmBinary($npm);
        }

        if ($chrome = config('pdf.chrome_path')) {
            $shot->setChromePath($chrome);
        }

        return $shot->pdf();
    }
}
