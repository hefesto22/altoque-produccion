<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $numero
 */
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    /** @var array<string, string> Estados del ciclo de vida de la cotización. */
    public const ESTADOS = [
        'borrador'  => 'Borrador',
        'enviada'   => 'Enviada',
        'aceptada'  => 'Aceptada',
        'rechazada' => 'Rechazada',
    ];

    /** @var array<string, string> Color Filament por estado (badges y botones). */
    public const ESTADO_COLORES = [
        'borrador'  => 'gray',
        'enviada'   => 'info',
        'aceptada'  => 'success',
        'rechazada' => 'danger',
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

    /** Fecha límite de validez de los precios cotizados. */
    public function validaHasta(): Carbon
    {
        return ($this->created_at ?? now())->copy()->addDays($this->validez_dias);
    }

    /** URL firmada del PDF — pública pero no adivinable (compartible). */
    public function urlPdf(): string
    {
        return URL::signedRoute('cotizaciones.pdf', ['cotizacion' => $this->id]);
    }

    /** Link de WhatsApp con el mensaje y la URL del PDF para el cliente. */
    public function urlWhatsApp(): string
    {
        $mensaje = "Cotización {$this->numero} — {$this->cliente_nombre}. Descárgala aquí: ".$this->urlPdf();

        return 'https://wa.me/?text='.rawurlencode($mensaje);
    }
}
