<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Cotización de evento: presupuesto con precios personalizados
 * (platillos completos, panas/cazuelas, carnes, lo que pida el cliente).
 *
 * NO es documento fiscal: no consume correlativo SAR. Si el evento se
 * concreta, la factura se emite por el flujo normal. Los totales se
 * guardan desglosados con el criterio "ISV incluido en el precio"
 * (los calcula CotizadorEventos, nunca se editan a mano).
 *
 * @property int $id
 * @property string $cliente_nombre
 * @property string|null $cliente_telefono
 * @property string|null $cliente_rtn
 * @property Carbon|null $evento_fecha
 * @property string|null $evento_lugar
 * @property int|null $personas
 * @property string $estado
 * @property int $validez_dias
 * @property float $descuento
 * @property float $subtotal
 * @property float $gravado
 * @property float $exento
 * @property float $isv
 * @property float $total
 * @property float|null $anticipo
 * @property string|null $notas
 * @property int|null $creado_por
 * @property int|null $venta_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $numero
 */
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    /** @var array<string, string> Estados del ciclo de vida de la cotización. */
    public const ESTADOS = [
        'borrador'   => 'Borrador',
        'enviada'    => 'Enviada',
        'aceptada'   => 'Aceptada',
        'rechazada'  => 'Rechazada',
        'completada' => 'Completada',
    ];

    /**
     * Estados que se pueden elegir A MANO. `completada` NO está: solo lo
     * asigna FacturadorEvento al emitir la factura — así nunca existe una
     * cotización "completada" sin su factura (cero margen de error).
     *
     * @var array<string, string>
     */
    public const ESTADOS_MANUALES = [
        'borrador'  => 'Borrador',
        'enviada'   => 'Enviada',
        'aceptada'  => 'Aceptada',
        'rechazada' => 'Rechazada',
    ];

    /** @var array<string, string> Color Filament por estado (badges y botones). */
    public const ESTADO_COLORES = [
        'borrador'   => 'gray',
        'enviada'    => 'info',
        'aceptada'   => 'success',
        'rechazada'  => 'danger',
        'completada' => 'primary',
    ];

    /** @var array<int, string> */
    protected $fillable = [
        'cliente_nombre',
        'cliente_telefono',
        'cliente_rtn',
        'evento_fecha',
        'evento_lugar',
        'personas',
        'estado',
        'validez_dias',
        'descuento',
        'subtotal',
        'gravado',
        'exento',
        'isv',
        'total',
        'anticipo',
        'notas',
        'creado_por',
        'venta_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evento_fecha' => 'date',
            'personas'     => 'integer',
            'validez_dias' => 'integer',
            'descuento'    => 'decimal:2',
            'subtotal'     => 'decimal:2',
            'gravado'      => 'decimal:2',
            'exento'       => 'decimal:2',
            'isv'          => 'decimal:2',
            'total'        => 'decimal:2',
            'anticipo'     => 'decimal:2',
        ];
    }

    /** Número legible: COT-00001 (correlativo simple, no fiscal). */
    public function getNumeroAttribute(): string
    {
        return 'COT-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<CotizacionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class)->orderBy('orden');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** @return HasMany<CotizacionPago, $this> Abonos internos (no fiscales). */
    public function pagos(): HasMany
    {
        return $this->hasMany(CotizacionPago::class)->orderBy('recibido_at');
    }

    /** @return BelongsTo<Venta, $this> La venta/factura emitida al completar el evento. */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /** Total abonado hasta ahora (suma de pagos internos). */
    public function pagado(): float
    {
        return round((float) $this->pagos()->sum('monto'), 2);
    }

    /**
     * Registra un abono y, si hace falta, mueve el estado solo.
     *
     * Un cliente que pone plata YA ACEPTÓ: pedirle después a la caja que
     * además cambie el estado a mano es papeleo que nadie hace, y la lista
     * termina llena de cotizaciones "borrador" con abonos encima.
     *
     * Solo avanza desde borrador/enviada. No toca `rechazada` (si abona una
     * rechazada, algo raro pasó y lo decide una persona) ni `completada`
     * (ese estado lo pone únicamente la facturación del evento).
     *
     * @return array{pago: CotizacionPago, estadoNuevo: string|null} estadoNuevo = null si no se movió
     */
    public function registrarAbono(float $monto, string $formaPago, ?string $banco, ?string $notas, ?int $usuarioId): array
    {
        return DB::transaction(function () use ($monto, $formaPago, $banco, $notas, $usuarioId): array {
            $pago = $this->pagos()->create([
                'monto'        => round($monto, 2),
                'forma_pago'   => $formaPago,
                'banco'        => in_array($formaPago, ['tarjeta', 'transferencia'], true) ? $banco : null,
                'notas'        => $notas,
                'recibido_por' => $usuarioId,
                'recibido_at'  => now(),
            ]);

            $estadoNuevo = null;

            if (in_array($this->estado, ['borrador', 'enviada'], true)) {
                $estadoNuevo = 'aceptada';
                $this->update(['estado' => $estadoNuevo]);
            }

            return ['pago' => $pago, 'estadoNuevo' => $estadoNuevo];
        });
    }

    /** Saldo pendiente de cobro (total − abonado). */
    public function saldo(): float
    {
        return round((float) $this->total - $this->pagado(), 2);
    }

    /** Fecha límite de validez de los precios cotizados. */
    public function validaHasta(): Carbon
    {
        return ($this->created_at ?? now())->copy()->addDays($this->validez_dias);
    }

    /**
     * URL firmada del PDF (lo arma Chromium, ~3 s).
     *
     * Ya NO se le ofrece a nadie: la vista del cliente resuelve el guardado
     * con el diálogo de impresión del teléfono, que no gasta CPU del servidor
     * ni deja un archivo por cotización en el disco. Queda como endpoint por
     * si alguna vez hace falta el archivo desde el servidor.
     */
    public function urlPdf(): string
    {
        return URL::signedRoute('cotizaciones.pdf', ['cotizacion' => $this->id]);
    }

    /**
     * URL firmada de la cotización para EL CLIENTE (la que va por WhatsApp).
     *
     * Es el HTML, no el PDF: abre al instante en el teléfono. El PDF levanta
     * un Chromium por request (~3 s, y 500 si dos personas abren a la vez) y
     * el cliente se queda viendo una pantalla en blanco. La página lleva su
     * propio botón de descarga para quien quiera el archivo.
     */
    public function urlCliente(): string
    {
        return URL::signedRoute('cotizaciones.ver', ['cotizacion' => $this->id, 'cliente' => 1]);
    }

    /** Link de WhatsApp con el mensaje y la cotización para el cliente. */
    public function urlWhatsApp(): string
    {
        $mensaje = "Cotización {$this->numero} — {$this->cliente_nombre}. Vela aquí: ".$this->urlCliente();

        return 'https://wa.me/?text='.rawurlencode($mensaje);
    }
}
