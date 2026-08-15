<?php

declare(strict_types=1);

namespace App\Services\Eventos;

use App\Models\BrandingSetting;
use App\Models\Cotizacion;
use App\Models\EmpresaSetting;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

/**
 * Genera el PDF de la cotización de evento en tamaño CARTA (documento
 * comercial para el cliente, no ticket térmico). Mismo pipeline
 * Browsershot que las facturas, con la config de Chromium para www-data.
 */
final class CotizacionPdfService
{
    /**
     * Cotización como HTML.
     *
     * @param bool $paraCliente vista pública (link de WhatsApp): se lee en el
     *                          teléfono y se guarda con el diálogo de impresión
     *                          del propio teléfono ("Guardar como PDF"). En
     *                          false es el documento carta tal cual — así lo
     *                          renderiza Browsershot para el PDF.
     */
    public function html(Cotizacion $cotizacion, bool $paraCliente = false): string
    {
        $cotizacion->loadMissing('items');

        $e = EmpresaSetting::actual();

        return view('pdf.cotizacion', [
            'c'           => $cotizacion,
            'paraCliente' => $paraCliente,
            'tasaIsv'     => (float) config('honduras.impuestos.isv.tasa_general', 0.15),
            'logo'        => $this->logoDataUri(),
            'sello'       => $this->selloDataUri(),
            'empresa'     => [
                'nombre'           => $e->nombreMostrar(),
                'razon_social'     => $e->razon_social,
                'nombre_comercial' => $e->nombre_comercial,
                'rtn'              => $e->rtn,
                'direccion'        => $e->direccion,
                'telefono'         => $e->telefono,
                // Comercial, no el fiscal: la cotización no es un documento
                // del RTN, es la carta de venta del negocio.
                'correo'           => $e->correoCotizaciones(),
            ],
        ])->render();
    }

    /** Bytes del PDF (tamaño carta). */
    public function pdf(Cotizacion $cotizacion): string
    {
        // Chromium necesita un HOME y un directorio de datos ESCRIBIBLES
        // (mismo arreglo que FacturaPdfService: bajo www-data el HOME viene
        // vacío y crashpad muere sin él).
        $userDataDir = storage_path('app/browsershot');

        if (! is_dir($userDataDir)) {
            mkdir($userDataDir, 0775, true);
        }

        $shot = Browsershot::html($this->html($cotizacion))
            ->format('Letter')
            ->margins(14, 14, 16, 14)
            ->showBackground()
            ->setNodeEnv(['HOME' => $userDataDir])
            ->noSandbox()
            ->userDataDir($userDataDir)
            ->addChromiumArguments(['disable-dev-shm-usage', 'disable-gpu']);

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

    /** Logo de la empresa como data URI (se embebe en el PDF, sin red). */
    private function logoDataUri(): ?string
    {
        return $this->imagenDataUri(BrandingSetting::current()->logo_path);
    }

    /** El sello de hule del negocio, si está cargado en Configuración. */
    private function selloDataUri(): ?string
    {
        return $this->imagenDataUri(BrandingSetting::current()->sello_path);
    }

    /**
     * Imagen del disco público como data URI.
     *
     * Embebida y no enlazada: Browsershot arma el PDF sin sesión ni acceso a
     * la app, y el link que abre el cliente tiene que verse completo aunque la
     * conexión del teléfono sea mala.
     */
    private function imagenDataUri(?string $path): ?string
    {
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

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
