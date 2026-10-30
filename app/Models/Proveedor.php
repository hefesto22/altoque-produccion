<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
    /** Máximo de nombres que devuelve una búsqueda del formulario de compras. */
    public const LIMITE_BUSQUEDA = 20;

    /** Recientes que se ofrecen al abrir el buscador, antes de escribir. */
    public const LIMITE_RECIENTES = 10;

    /** Laravel pluralizaría "proveedors". */
    protected $table = 'proveedores';

    /** @var array<int, string> */
    protected $fillable = ['nombre', 'rtn'];

    /**
     * Registra o actualiza un proveedor por su nombre normalizado.
     *
     * Si esta compra vino sin RTN (un recibo, por ejemplo) NO se borra el
     * RTN que ya se conocía: se perdería el dato bueno de una factura previa.
     *
     * Aunque no cambie nada se marca como usado (updated_at): de ahí sale la
     * lista de recientes que ofrece el buscador al abrirse.
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

        // save() sin cambios no toca la base, y el proveedor dejaría de
        // subir en la lista de recientes aunque se acabe de usar.
        if ($proveedor->exists && ! $proveedor->isDirty()) {
            $proveedor->touch();
        } else {
            $proveedor->save();
        }

        return $proveedor;
    }

    /**
     * Buscador del formulario de compras: hasta LIMITE_BUSQUEDA nombres que
     * contienen lo escrito; primero los que EMPIEZAN así, después en orden
     * alfabético. Sin texto, devuelve los recientes.
     *
     * Con factura solo salen los proveedores con RTN (sin RTN el SAR no
     * acepta el descuento del ISV); con recibo, solo los que no lo tienen.
     *
     * Se busca en la base con límite en vez de mandar el catálogo entero al
     * navegador. El catálogo crece de a un proveedor nuevo por vez —miles,
     * no millones—, así que un LIKE sobre `nombre` sin índice especial sobra.
     *
     * @return array<string, string> nombre => nombre (formato de opciones del Select)
     */
    public static function buscar(string $termino, bool $conRtn): array
    {
        $termino = mb_strtoupper(trim($termino));

        if ($termino === '') {
            return self::recientes($conRtn);
        }

        // Un % o _ escrito por el usuario es texto, no comodín.
        $patron = addcslashes($termino, '\\%_');

        $nombres = self::delTipo($conRtn)
            ->where('nombre', 'like', '%'.$patron.'%')
            ->orderByRaw('CASE WHEN nombre LIKE ? THEN 0 ELSE 1 END', [$patron.'%'])
            ->orderBy('nombre')
            ->limit(self::LIMITE_BUSQUEDA)
            ->pluck('nombre')
            ->all();

        return array_combine($nombres, $nombres);
    }

    /**
     * Los últimos proveedores usados del tipo pedido: lo que ofrece el
     * buscador al abrirse, antes de escribir nada.
     *
     * @return array<string, string> nombre => nombre
     */
    public static function recientes(bool $conRtn): array
    {
        $nombres = self::delTipo($conRtn)
            ->orderByDesc('updated_at')
            ->limit(self::LIMITE_RECIENTES)
            ->pluck('nombre')
            ->all();

        return array_combine($nombres, $nombres);
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

    /**
     * Proveedores con RTN (los de factura) o sin RTN (los de recibo). Un RTN
     * vacío cuenta como "sin RTN".
     *
     * @return Builder<Proveedor>
     */
    private static function delTipo(bool $conRtn): Builder
    {
        $query = Proveedor::query();

        if ($conRtn) {
            return $query->whereNotNull('rtn')->where('rtn', '<>', '');
        }

        return $query->where(static function (Builder $q): void {
            $q->whereNull('rtn')->orWhere('rtn', '');
        });
    }
}
