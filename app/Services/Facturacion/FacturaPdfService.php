<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Models\BrandingSetting;
use App\Models\Comanda;
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
        return view('pdf.factura', $this->datosVista($factura))->render();
    }

    /**
     * Factura + comanda en UN solo documento (dos páginas con salto):
     * una sola ventana de impresión en caja; la térmica corta entre tickets.
     */
    public function htmlConComanda(Factura $factura, Comanda $comanda): string
    {
        return view('pdf.venta-documentos', [
            ...$this->datosVista($factura),
            'comanda' => $comanda,
        ])->render();
    }

    /** @return array<string, mixed> Datos comunes de las vistas de factura. */
    private function datosVista(Factura $factura): array
    {
        $factura->loadMissing(['venta.items', 'venta.pagos', 'venta.comanda', 'cai']);

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

        return [
            'f'       => $factura,
            'empresa' => $empresa,
            'logo'    => $this->logoDataUri(),
            // Por factura si se definió; si no, el default de la empresa.
            'detallada' => $factura->detallada ?? $e->factura_detallada,
            'qr'        => $factura->hash_verificacion !== null
                ? $this->qr->dataUri($factura->urlVerificacion(), 150)
                : null,
        ];
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
        // Chromium necesita un HOME y un directorio de datos ESCRIBIBLES para
        // arrancar (ahí crea su perfil y la base de crashpad). Bajo www-data el
        // HOME viene vacío/no escribible, por eso el proceso moría con
        // "Failed to launch / crashpad: --database is required". Apuntamos ambos
        // a storage/, que ya tiene permisos para www-data.
        $userDataDir = storage_path('app/browsershot');

        if (! is_dir($userDataDir)) {
            mkdir($userDataDir, 0775, true);
        }

        $shot = Browsershot::html($this->html($factura))
            ->paperSize(80, 250, 'mm')
            ->margins(3, 3, 3, 3)
            ->showBackground()
            // Imprescindible: sin un HOME escribible Chromium no levanta su
            // proceso (crashpad: "--database is required"). Se lo inyectamos al
            // comando de node, igual que PATH.
            ->setNodeEnv(['HOME' => $userDataDir])
            // Chromium corre bajo www-data sin namespaces de usuario: el sandbox
            // no puede levantar su proceso y crashea. --no-sandbox lo resuelve.
            // --disable-dev-shm-usage evita crashes por /dev/shm chico en el VPS;
            // --disable-gpu porque es headless sin tarjeta.
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
}
