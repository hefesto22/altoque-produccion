<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cliente frecuente: se guarda al emitir factura con RTN, para autocompletar
 * en próximas ventas (buscar por nombre o RTN). El nombre siempre en mayúsculas.
 *
 * @property int $id
 * @property string $rtn
 * @property string $nombre
 */
class Cliente extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['rtn', 'nombre'];

    /** Registra o actualiza un cliente por su RTN, normalizando el nombre. */
    public static function registrar(string $rtn, string $nombre): self
    {
        return static::updateOrCreate(
            ['rtn' => $rtn],
            ['nombre' => mb_strtoupper(trim($nombre))],
        );
    }
}
