<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Venta — registro central. SIEMPRE existe y SIEMPRE lleva su desglose
 * fiscal (gravado/exento/isv), sea recibo no fiscal o factura SAR.
 *
 * La diferencia entre recibo y factura es solo si se asigna correlativo
 * SAR y se imprime documento fiscal (relación factura), no si se calcula
 * el impuesto.
 *
 * @property int $id
 * @property int $cajero_id
 * @property int|null $corte_caja_id
 * @property string $tipo
 * @property string $tipo_orden
 * @property string|null $numero_orden
 * @property string|null $numero_recibo
 * @property string|null $rtn_cliente
 * @property string|null $nombre_cliente
 * @property float $gravado
 * @property float $exento
 * @property float $subtotal_lista
 * @property float $descuento
 * @property float $isv
 * @property float $total
 * @property float $costo_viaje
 * @property bool $pagada
 * @property Carbon|null $pagada_at
 * @property Carbon $vendida_at
 */
class Venta extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'cajero_id',
        'corte_caja_id',
        'tipo',
        'tipo_orden',
        'numero_orden',
        'forma_pago',
        'banco',
        'numero_recibo',
        'rtn_cliente',
        'nombre_cliente',
        'nombre_orden',
        'gravado',
        'exento',
        'subtotal_lista',
        'descuento',
        'isv',
        'total',
        'costo_viaje',
        'pagada',
        'pagada_at',
        'vendida_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gravado'        => 'decimal:2',
            'exento'         => 'decimal:2',
            'subtotal_lista' => 'decimal:2',
            'descuento'      => 'decimal:2',
            'isv'            => 'decimal:2',
            'total'          => 'decimal:2',
            'costo_viaje'    => 'decimal:2',
            'pagada'         => 'boolean',
            'pagada_at'      => 'datetime',
            'vendida_at'     => 'datetime',
        ];
    }

    /** @return HasMany<VentaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    /**
     * Pagos de la venta: 1 fila si es simple, 2–3 si es mixta.
     * La suma de montos siempre cuadra con total.
     *
     * @return HasMany<VentaPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(VentaPago::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cajero_id');
    }

    /** @return BelongsTo<CorteCaja, $this> */
    public function corte(): BelongsTo
    {
        return $this->belongsTo(CorteCaja::class, 'corte_caja_id');
    }

    /** @return HasOne<Factura, $this> */
    public function factura(): HasOne
    {
        return $this->hasOne(Factura::class);
    }

    /**
     * Comanda de cocina de esta venta (si la orden fue a cocina).
     *
     * @return HasOne<Comanda, $this>
     */
    public function comanda(): HasOne
    {
        return $this->hasOne(Comanda::class)->latestOfMany();
    }

    public function esFactura(): bool
    {
        return $this->tipo === 'factura';
    }

    /** Law of Demeter: la venta encapsula el nombre del cajero. */
    public function getCajeroNombreAttribute(): ?string
    {
        return $this->cajero?->name;
    }

    /**
     * @param Builder<Venta> $query
     *
     * @return Builder<Venta>
     */
    public function scopeFiscales(Builder $query): Builder
    {
        return $query->where('tipo', 'factura');
    }

    /**
     * @param Builder<Venta> $query
     *
     * @return Builder<Venta>
     */
    public function scopeDelTurno(Builder $query, int $corteCajaId): Builder
    {
        return $query->where('corte_caja_id', $corteCajaId);
    }

    /**
     * @param Builder<Venta> $query
     *
     * @return Builder<Venta>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('pagada', false);
    }
}
