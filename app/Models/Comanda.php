<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Comanda de cocina para pedidos 'para llevar' o 'a domicilio'. El buffet
 * servido en el local no genera comanda.
 *
 * Flujo de estado: pendiente → preparando → listo → entregado.
 *
 * @property int $id
 * @property int $venta_id
 * @property string $numero
 * @property string $tipo
 * @property string $estado
 * @property string|null $cliente_nombre
 * @property string|null $cliente_telefono
 * @property string|null $cliente_identidad
 * @property string|null $cliente_direccion
 * @property Carbon|null $listo_at
 * @property Carbon|null $entregado_at
 * @property Carbon $created_at
 */
class Comanda extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'venta_id',
        'numero',
        'tipo',
        'estado',
        'items',
        'cliente_nombre',
        'cliente_telefono',
        'cliente_identidad',
        'cliente_direccion',
        'listo_at',
        'entregado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'items'        => 'array',
            'listo_at'     => 'datetime',
            'entregado_at' => 'datetime',
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

    /** @param  Builder<Comanda>  $query */
    public function scopeEnCocina(Builder $query): Builder
    {
        return $query->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->orderBy('created_at');
    }
}
