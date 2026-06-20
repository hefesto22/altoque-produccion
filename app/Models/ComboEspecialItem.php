<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto que compone un platillo armado (combo especial en modo
 * 'platillo'). Ej: el desayuno lleva pollo + embutido + huevo + frijoles.
 *
 * @property int $id
 * @property int $combo_id
 * @property int $producto_id
 * @property int $cantidad
 * @property int $orden
 */
class ComboEspecialItem extends Model
{
    protected $table = 'combo_especial_items';

    /** @var array<int, string> */
    protected $fillable = [
        'combo_id',
        'producto_id',
        'cantidad',
        'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'orden'    => 'integer',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
