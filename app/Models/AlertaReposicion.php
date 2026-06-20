<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerta de que un complemento del buffet se está acabando. La cocina la
 * ve y, al reponer, la marca como repuesta.
 *
 * @property int $id
 * @property int $producto_id
 * @property string $estado
 * @property int|null $creada_por
 * @property int|null $repuesta_por
 * @property Carbon|null $repuesta_at
 * @property Carbon $created_at
 */
class AlertaReposicion extends Model
{
    protected $table = 'alertas_reposicion';

    /** @var array<int, string> */
    protected $fillable = [
        'producto_id',
        'estado',
        'creada_por',
        'repuesta_por',
        'repuesta_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'repuesta_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** @param  Builder<AlertaReposicion>  $query */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', 'activa')->orderBy('created_at');
    }
}
