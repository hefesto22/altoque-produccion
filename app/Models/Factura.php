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

    /** URL firmada del PDF — pública pero no adivinable (compartible). */
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

    /** Link de WhatsApp con el mensaje y la URL del PDF para el cliente. */
    public function urlWhatsApp(): string
    {
        $mensaje = "Factura {$this->numero} — {$this->nombre_cliente}. Descárgala aquí: ".$this->urlPdf();

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
