<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disponibilidad de un combo en un servicio de una fecha concreta. El
 * conjunto de filas de (fecha, servicio) son los combos que se anuncian
 * en la pantalla del menú ese día.
 *
 * @property int $id
 * @property Carbon $fecha
 * @property int $servicio_id
 * @property int $combo_id
 */
class MenuDiaCombo extends Model
{
    protected $table = 'menu_dia_combos';

    /** @var array<int, string> */
    protected $fillable = [
        'fecha',
        'servicio_id',
        'combo_id',
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

    /** @return BelongsTo<Combo, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }
}
