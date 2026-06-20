<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ComboFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de precio de combo: para un tier de proteína y una cantidad de
 * complementos, define el precio del plato. Configurable desde el panel,
 * nunca hardcodeado.
 *
 * @property int $id
 * @property string $tier
 * @property int $complementos
 * @property float $precio
 * @property bool $activo
 */
class Combo extends Model
{
    /** @use HasFactory<ComboFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'tier',
        'complementos',
        'precio',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'complementos' => 'integer',
            'precio'       => 'decimal:2',
            'activo'       => 'boolean',
        ];
    }

    /** @param  Builder<Combo>  $query */
    public function scopeActivo(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
