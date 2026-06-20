<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServicioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Servicio del día: desayuno, almuerzo o cena, con su ventana horaria.
 * El POS usa la hora actual para saber qué servicio está activo y mostrar
 * su menú.
 *
 * @property int $id
 * @property string $nombre
 * @property string $slug
 * @property string $hora_inicio
 * @property string $hora_fin
 * @property int $orden
 * @property bool $activo
 */
class Servicio extends Model
{
    /** @use HasFactory<ServicioFactory> */
    use HasFactory;

    protected $table = 'servicios';

    /** @var array<int, string> */
    protected $fillable = [
        'nombre',
        'slug',
        'hora_inicio',
        'hora_fin',
        'orden',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'orden'  => 'integer',
            'activo' => 'boolean',
        ];
    }

    /** @param  Builder<Servicio>  $query */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    /**
     * Servicio cuya ventana horaria contiene la hora dada (o la actual).
     * Devuelve null si ningún servicio está activo en ese momento.
     */
    public static function activoAhora(?Carbon $referencia = null): ?self
    {
        $hora = ($referencia ?? now())->format('H:i:s');

        return static::query()
            ->where('activo', true)
            ->where('hora_inicio', '<=', $hora)
            ->where('hora_fin', '>=', $hora)
            ->orderBy('orden')
            ->first();
    }

    public function ventana(): string
    {
        return Carbon::parse($this->hora_inicio)->format('g:i A')
            .' – '.Carbon::parse($this->hora_fin)->format('g:i A');
    }
}
