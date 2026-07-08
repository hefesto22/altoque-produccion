<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Comanda de cocina para pedidos 'para llevar', 'a domicilio' o 'en el
 * local'. El buffet de local cobrado al momento también genera comanda
 * (configurable en Datos de la Empresa), pero nace ya ENTREGADA: se
 * imprime su ticket junto a la factura sin pasar por el KDS.
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

    /** Etiqueta legible del tipo de orden (para ticket y pantallas). */
    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'domicilio' => 'A domicilio',
            'llevar'    => 'Para llevar',
            default     => 'En el local',
        };
    }

    /** URL firmada del ticket imprimible de la comanda (80mm, para cocina). */
    public function urlTicket(): string
    {
        return URL::signedRoute('comandas.ticket', ['comanda' => $this->id]);
    }

    /** @param  Builder<Comanda>  $query */
    public function scopeEnCocina(Builder $query): Builder
    {
        // Las que aún se preparan van primero; las "listo" se van al final.
        return $query->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->orderByRaw("case when estado = 'listo' then 1 else 0 end")
            ->orderBy('created_at');
    }
}
