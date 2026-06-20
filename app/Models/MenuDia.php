<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disponibilidad de un producto en un servicio de una fecha concreta.
 * El conjunto de filas de (fecha, servicio) es el menú de ese servicio
 * ese día.
 *
 * @property int $id
 * @property Carbon $fecha
 * @property int $servicio_id
 * @property int $producto_id
 */
class MenuDia extends Model
{
    protected $table = 'menu_dia';

    /** @var array<int, string> */
    protected $fillable = [
        'fecha',
        'servicio_id',
        'producto_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    /** @return BelongsTo<Servicio, $this> */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
