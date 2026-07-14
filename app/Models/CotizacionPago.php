<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Abono parcial de una cotización de evento (anticipo, pagos a cuenta).
 *
 * NO es documento fiscal: es el registro interno de cuánto ha pagado el
 * cliente. La factura SAR se emite UNA vez, por el total, al completar
 * el evento (ver FacturadorEvento).
 *
 * @property int $id
 * @property int $cotizacion_id
 * @property float $monto
 * @property string $forma_pago
 * @property string|null $banco
 * @property string|null $notas
 * @property int|null $recibido_por
 * @property Carbon $recibido_at
 */
class CotizacionPago extends Model
{
    protected $table = 'cotizacion_pagos';

    /** @var array<int, string> */
    protected $fillable = [
        'cotizacion_id',
        'monto',
        'forma_pago',
        'banco',
        'notas',
        'recibido_por',
        'recibido_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'       => 'decimal:2',
            'recibido_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cotizacion, $this> */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
