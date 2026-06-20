<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido hecho desde la página pública. Entra como 'pendiente' y el
 * personal lo confirma (genera venta + comanda) o lo rechaza.
 *
 * @property int $id
 * @property string $numero
 * @property string $tipo
 * @property string $estado
 * @property string $cliente_nombre
 * @property string $cliente_telefono
 * @property string|null $cliente_identidad
 * @property string|null $cliente_direccion
 * @property string|null $notas
 * @property array<int, array<string, mixed>> $items
 * @property float $total
 * @property string $metodo_pago
 * @property string|null $comprobante_path
 * @property int|null $venta_id
 * @property Carbon $created_at
 */
class PedidoOnline extends Model
{
    protected $table = 'pedidos_online';

    /** @var array<int, string> */
    protected $fillable = [
        'numero', 'tipo', 'estado',
        'cliente_nombre', 'cliente_telefono', 'cliente_identidad', 'cliente_direccion', 'notas',
        'items', 'total', 'metodo_pago', 'comprobante_path',
        'venta_id', 'confirmado_por', 'confirmado_at', 'motivo_rechazo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'items'         => 'array',
            'total'         => 'decimal:2',
            'confirmado_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function esDomicilio(): bool
    {
        return $this->tipo === 'domicilio';
    }

    /** @param  Builder<PedidoOnline>  $query */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', 'pendiente')->orderBy('created_at');
    }
}
