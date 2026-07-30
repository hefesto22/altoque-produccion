<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Factura SAR (autoimpreso). Documento fiscal con correlativo único e
 * irrepetible dentro de un CAI.
 *
 * NUNCA se borra: se anula con motivo y registro. softDeletes no
 * sustituye la anulación fiscal.
 *
 * @property int $id
 * @property int $venta_id
 * @property int $cai_id
 * @property int $correlativo
 * @property string $numero
 * @property string $rtn_cliente
 * @property string $nombre_cliente
 * @property float $gravado
 * @property float $exento
 * @property float $subtotal_lista
 * @property float $descuento
 * @property float $isv
 * @property float $total
 * @property bool $anulada
 * @property string|null $motivo_anulacion
 * @property Carbon|null $anulada_at
 * @property Carbon $emitida_at
 */
class Factura extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'venta_id',
        'cai_id',
        'correlativo',
        'numero',
        'detallada',
        'forma_pago',
        'pagos_detalle',
        'hash_verificacion',
        'rtn_cliente',
        'nombre_cliente',
        'gravado',
        'exento',
        'subtotal_lista',
        'descuento',
        'isv',
        'total',
        'anulada',
        'motivo_anulacion',
        'anulada_at',
        'emitida_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'correlativo'    => 'integer',
            'detallada'      => 'boolean',
            'pagos_detalle'  => 'array',
            'gravado'        => 'decimal:2',
            'exento'         => 'decimal:2',
            'subtotal_lista' => 'decimal:2',
            'descuento'      => 'decimal:2',
            'isv'            => 'decimal:2',
            'total'          => 'decimal:2',
            'anulada'        => 'boolean',
            'anulada_at'     => 'datetime',
            'emitida_at'     => 'datetime',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /** @return BelongsTo<Cai, $this> */
    public function cai(): BelongsTo
    {
        return $this->belongsTo(Cai::class);
    }

    /**
     * URL firmada del PDF (lo genera Chromium, ~3 s).
     *
     * Ya NO se le ofrece al cliente: la vista pública lo resuelve con el
     * diálogo de impresión del teléfono ("Guardar como PDF"), que no gasta CPU
     * del servidor ni deja un archivo por venta en el disco. Queda como
     * endpoint por si alguna vez hace falta el archivo desde el servidor.
     */
    public function urlPdf(): string
    {
        return URL::signedRoute('facturas.pdf', ['factura' => $this->id]);
    }

    /** URL firmada de la factura como HTML — impresión instantánea en caja. */
    public function urlTicket(): string
    {
        return URL::signedRoute('facturas.ticket', ['factura' => $this->id]);
    }

    /** Factura + comanda en un solo documento imprimible (una sola ventana). */
    public function urlDocumentos(): string
    {
        return URL::signedRoute('facturas.documentos', ['factura' => $this->id]);
    }

    /**
     * URL firmada de la factura para EL CLIENTE (la que va por WhatsApp).
     *
     * Es el HTML, no el PDF: abre al instante desde el teléfono. El PDF
     * levanta un Chromium en el servidor (~3 s, y 500 si dos personas abren a
     * la vez) y además obligaría a guardar archivos para que fuera rápido.
     * La página lleva su propio botón de descarga para quien quiera el archivo.
     */
    public function urlCliente(): string
    {
        return URL::signedRoute('facturas.ticket', ['factura' => $this->id, 'cliente' => 1]);
    }

    /** Link de WhatsApp con el mensaje y la factura para el cliente. */
    public function urlWhatsApp(): string
    {
        $mensaje = "Factura {$this->numero} — {$this->nombre_cliente}. Vela aquí: ".$this->urlCliente();

        return 'https://wa.me/?text='.rawurlencode($mensaje);
    }

    /**
     * Calcula el hash de verificación HMAC-SHA256 a partir de los datos
     * fiscales de la factura. Determinístico y firmado con APP_KEY: el
     * cliente no puede falsificar uno válido.
     */
    public static function calcularHash(string $numero, string $rtn, float|string $total, int $caiId): string
    {
        return hash_hmac('sha256', "{$numero}|{$rtn}|{$total}|{$caiId}", (string) config('app.key'));
    }

    /** URL pública de verificación de autenticidad (la que va en el QR). */
    public function urlVerificacion(): string
    {
        return url('/verificar/'.$this->hash_verificacion);
    }
}
