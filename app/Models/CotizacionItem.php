<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una cotización de evento. Snapshot en texto libre: aunque el
 * ítem se haya prellenado desde el catálogo, acá queda la descripción y
 * el precio PERSONALIZADO pactado para el evento — cambiar el catálogo
 * después no altera cotizaciones ya entregadas.
 *
 * @property int $id
 * @property int $cotizacion_id
 * @property string $descripcion
 * @property float $cantidad
 * @property float $precio_unitario
 * @property bool $grava_isv
 * @property int $orden
 */
class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';

    /** @var array<int, string> */
    protected $fillable = [
        'cotizacion_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'grava_isv',
        'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad'        => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'grava_isv'       => 'boolean',
            'orden'           => 'integer',
        ];
    }

    /** @return BelongsTo<Cotizacion, $this> */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /** Importe de la línea (cantidad × precio personalizado). */
    public function importe(): float
    {
        return round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }
}
