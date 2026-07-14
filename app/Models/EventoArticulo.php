<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Artículo del catálogo de EVENTOS: cosas que solo se cotizan para
 * eventos (panas, cazuelas, paquetes por persona…) con su precio
 * personalizado — separado del catálogo del menú y sus precios de carta.
 *
 * Se alimenta solo (mismo criterio que Cliente::registrar): al guardar
 * una cotización, cada ítem se registra/actualiza aquí por nombre con
 * su último precio, para autocompletar la próxima vez.
 *
 * @property int $id
 * @property string $nombre
 * @property float $precio
 * @property bool $grava_isv
 * @property bool $activo
 */
class EventoArticulo extends Model
{
    protected $table = 'evento_articulos';

    /** @var array<int, string> */
    protected $fillable = ['nombre', 'precio', 'grava_isv', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precio'    => 'decimal:2',
            'grava_isv' => 'boolean',
            'activo'    => 'boolean',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActivos(Builder $query): void
    {
        $query->where('activo', true);
    }

    /** Registra o actualiza un artículo por nombre, con su último precio cotizado. */
    public static function registrar(string $nombre, float $precio, bool $gravaIsv): self
    {
        return static::updateOrCreate(
            ['nombre' => mb_strtoupper(trim($nombre))],
            ['precio' => $precio, 'grava_isv' => $gravaIsv, 'activo' => true],
        );
    }
}
