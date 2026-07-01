<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Corte de caja (turno) de un cajero. Mientras está 'abierto' las ventas
 * se le asocian; al 'cerrar' se congela el snapshot y se concilia el
 * efectivo contado contra lo esperado.
 *
 * @property int $id
 * @property int $cajero_id
 * @property float $fondo_inicial
 * @property float $fondo_terminal
 * @property string $estado
 * @property Carbon $abierto_at
 * @property Carbon|null $cerrado_at
 * @property float $total_ventas
 * @property float $total_efectivo
 * @property float $total_tarjeta
 * @property float $total_transferencia
 * @property float $total_isv
 * @property int $cantidad_ventas
 * @property float|null $efectivo_contado
 * @property float|null $diferencia
 */
class CorteCaja extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'cajero_id', 'fondo_inicial', 'fondo_terminal', 'estado', 'abierto_at', 'cerrado_at',
        'total_ventas', 'total_efectivo', 'total_tarjeta', 'total_transferencia', 'total_isv',
        'cantidad_ventas', 'efectivo_contado', 'diferencia', 'notas',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fondo_inicial'       => 'decimal:2',
            'fondo_terminal'      => 'decimal:2',
            'abierto_at'          => 'datetime',
            'cerrado_at'          => 'datetime',
            'total_ventas'        => 'decimal:2',
            'total_efectivo'      => 'decimal:2',
            'total_tarjeta'       => 'decimal:2',
            'total_transferencia' => 'decimal:2',
            'total_isv'           => 'decimal:2',
            'cantidad_ventas'     => 'integer',
            'efectivo_contado'    => 'decimal:2',
            'diferencia'          => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cajero_id');
    }

    /** @return HasMany<Venta, $this> */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    /** Efectivo esperado en caja: fondo + ventas en efectivo. */
    public function efectivoEsperado(): float
    {
        return (float) $this->fondo_inicial + (float) $this->total_efectivo;
    }
}
