<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor frecuente. Se guarda solo al registrar una compra, para que la
 * próxima vez el nombre aparezca sugerido y el RTN se llene automáticamente
 * (mismo patrón que `Cliente` en el POS). El nombre siempre en MAYÚSCULAS.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $rtn
 */
class Proveedor extends Model
{
    /** Laravel pluralizaría "proveedors". */
    protected $table = 'proveedores';

    /** @var array<int, string> */
    protected $fillable = ['nombre', 'rtn'];

    /**
     * Registra o actualiza un proveedor por su nombre normalizado.
     *
     * Si esta compra vino sin RTN (un recibo, por ejemplo) NO se borra el
     * RTN que ya se conocía: se perdería el dato bueno de una factura previa.
     */
    public static function registrar(string $nombre, ?string $rtn = null): ?self
    {
        $nombre = mb_strtoupper(trim($nombre));

        if ($nombre === '') {
            return null;
        }

        $rtn = trim((string) $rtn);

        $proveedor = static::firstOrNew(['nombre' => $nombre]);

        if ($rtn !== '') {
            $proveedor->rtn = $rtn;
        }

        $proveedor->save();

        return $proveedor;
    }

    /**
     * Nombres para el autocompletado del formulario de compras.
     *
     * @return array<int, string>
     */
    public static function nombres(): array
    {
        return static::query()->orderBy('nombre')->pluck('nombre')->all();
    }

    /** RTN conocido de un proveedor por su nombre (sin importar mayúsculas). */
    public static function rtnDe(?string $nombre): ?string
    {
        $nombre = mb_strtoupper(trim((string) $nombre));

        if ($nombre === '') {
            return null;
        }

        return static::query()->where('nombre', $nombre)->value('rtn');
    }
}
