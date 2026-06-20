<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tier = nivel de precio de proteína. Agrupa proteínas que comparten el
 * mismo precio de combo (ej: "Pollo / Cerdo", "Res", "Pescado").
 *
 * Las proteínas (productos.tier_combo) y las reglas de precio (combos.tier)
 * referencian el `codigo` del tier. El código es estable: aunque se cambie
 * el nombre, el cobro sigue funcionando porque la coincidencia es por código.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int $orden
 * @property bool $activo
 */
class Tier extends Model
{
    private const CACHE_MAPA = 'tiers:mapa';

    /** @var array<int, string> */
    protected $fillable = ['codigo', 'nombre', 'orden', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'orden'  => 'integer',
            'activo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Código estable autogenerado del nombre si no se indicó.
        static::creating(static function (Tier $tier): void {
            if (blank($tier->codigo)) {
                $tier->codigo = (string) Str::of($tier->nombre)->slug('_');
            }
        });

        static::saved(static fn () => Cache::forget(self::CACHE_MAPA));
        static::deleted(static fn () => Cache::forget(self::CACHE_MAPA));
    }

    /** @param  Builder<Tier>  $query */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Mapa código → nombre (cacheado), para mostrar el nombre legible del
     * tier donde solo se tiene el código. Evita consultas repetidas.
     *
     * @return array<string, string>
     */
    public static function mapa(): array
    {
        return Cache::rememberForever(self::CACHE_MAPA, static fn (): array => self::query()
            ->orderBy('orden')
            ->pluck('nombre', 'codigo')
            ->all());
    }

    /**
     * Opciones código → nombre de tiers activos, para los selects.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return self::query()->activos()->orderBy('orden')->pluck('nombre', 'codigo')->all();
    }
}
