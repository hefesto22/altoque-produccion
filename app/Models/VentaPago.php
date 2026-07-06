<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago de una venta. Una venta simple tiene exactamente un pago;
 * una venta con pago mixto tiene 2–3 (efectivo + tarjeta + transferencia).
 *
 * Snapshot inmutable: la suma de los montos SIEMPRE cuadra con
 * ventas.total (lo garantiza VentaService antes de crear las filas).
 *
 * @property int $id
 * @property int $venta_id
 * @property string $metodo
 * @property string|null $banco
 * @property float $monto
 */
class VentaPago extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'venta_id',
        'metodo',
        'banco',
        'monto',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
