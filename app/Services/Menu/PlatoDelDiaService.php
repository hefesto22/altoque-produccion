<?php

declare(strict_types=1);

namespace App\Services\Menu;

use App\Models\ComboEspecial;
use App\Models\ComboEspecialItem;
use App\Models\MenuDia;
use App\Models\VentaItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Plato especial del día: el platillo que la cocina hace solo hoy.
 *
 * No es un tipo nuevo de producto. Es un platillo completo (categoría
 * 'combo', modo 'platillo') con `fecha_especial` puesta, así:
 *
 *  - se cobra, factura y manda a cocina por el camino que ya existe;
 *  - el POS lo personaliza igual que cualquier platillo armado;
 *  - solo aparece en el menú de SU fecha, y ahí aparece siempre: la
 *    publicación es la columna `fecha_especial`, NO filas en `menu_dia`.
 *    Marcarlo en menu_dia haría que la fecha contara como "menú cargado" y
 *    tumbaría la tolerancia que muestra el catálogo completo cuando nadie
 *    armó el menú del día.
 *
 * Un plato ya vendido nunca se borra: `venta_items` guarda la FK y el
 * desglose fiscal del período tiene que seguir cuadrando.
 */
final class PlatoDelDiaService
{
    /**
     * Crea el plato. Queda publicado en los tres servicios de esa fecha por
     * su `fecha_especial`, sin tocar el menú del día de nadie.
     *
     * @param array<int, int> $productoIds productos del catálogo que lo componen
     */
    public function crear(
        Carbon $fecha,
        string $nombre,
        float $precio,
        array $productoIds,
        ?string $nota = null,
        bool $gravaIsv = true,
    ): ComboEspecial {
        return DB::transaction(function () use ($fecha, $nombre, $precio, $productoIds, $nota, $gravaIsv): ComboEspecial {
            /** @var ComboEspecial $plato */
            $plato = ComboEspecial::query()->create([
                'nombre'         => $nombre,
                'precio'         => $precio,
                'grava_isv'      => $gravaIsv,
                'activo'         => true,
                'combo_modo'     => 'platillo',
                'descripcion'    => $nota,
                'fecha_especial' => $fecha->toDateString(),
            ]);

            $orden = 0;

            foreach (array_values(array_unique($productoIds)) as $productoId) {
                ComboEspecialItem::query()->create([
                    'combo_id'    => $plato->id,
                    'producto_id' => $productoId,
                    'cantidad'    => 1,
                    'orden'       => $orden++,
                ]);
            }

            return $plato;
        });
    }

    /**
     * Platos especiales de una fecha, con su composición cargada.
     *
     * @return Collection<int, ComboEspecial>
     */
    public function delDia(Carbon $fecha): Collection
    {
        return ComboEspecial::query()
            ->delDia($fecha)
            ->with('items.producto:id,nombre,categoria')
            ->orderBy('nombre')
            ->get();
    }

    /** ¿Ya entró en alguna venta? Entonces es historia fiscal, no se toca. */
    public function seVendio(ComboEspecial $plato): bool
    {
        return VentaItem::query()->where('producto_id', $plato->id)->exists();
    }

    /**
     * Quita el plato del menú. Si nunca se vendió se borra (soft delete);
     * si ya se vendió solo se despublica: sigue en la base para que el
     * histórico y el desglose de ISV no se rompan.
     */
    public function eliminar(ComboEspecial $plato): bool
    {
        $vendido = $this->seVendio($plato);

        DB::transaction(function () use ($plato, $vendido): void {
            MenuDia::query()->where('producto_id', $plato->id)->delete();

            if ($vendido) {
                $plato->activo = false;
                $plato->save();

                return;
            }

            ComboEspecialItem::query()->where('combo_id', $plato->id)->delete();
            $plato->delete();
        });

        return ! $vendido;
    }
}
