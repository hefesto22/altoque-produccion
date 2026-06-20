<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Combo;
use App\Models\MenuDia;
use App\Models\MenuDiaCombo;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Resuelve qué productos del catálogo están disponibles para una fecha y
 * servicio, según el menú del día.
 *
 * Regla de tolerancia: si esa fecha NO tiene ningún menú cargado, el POS
 * muestra todo el catálogo activo (no se bloquea la caja). Si la fecha sí
 * tiene menú pero para otro servicio, ese servicio aparece vacío.
 */
final class MenuDiaService
{
    /**
     * @return Collection<int, Producto>
     */
    public function disponibles(Carbon $fecha, ?int $servicioId): Collection
    {
        $query = Producto::query()->activos()
            ->select(['id', 'nombre', 'categoria', 'tier_combo', 'descripcion', 'precio', 'grava_isv'])
            ->orderBy('nombre');

        if ($this->hayMenuCargado($fecha) && $servicioId !== null) {
            $ids = MenuDia::query()
                ->whereDate('fecha', $fecha)
                ->where('servicio_id', $servicioId)
                ->pluck('producto_id');

            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /** ¿Esa fecha tiene algún menú del día cargado (cualquier servicio)? */
    public function hayMenuCargado(Carbon $fecha): bool
    {
        return MenuDia::query()->whereDate('fecha', $fecha)->exists();
    }

    /**
     * Sincroniza el menú de un servicio en una fecha con la lista dada de
     * productos (reemplaza lo anterior para ese fecha+servicio).
     *
     * @param array<int, int> $productoIds
     */
    public function sincronizar(Carbon $fecha, int $servicioId, array $productoIds): void
    {
        MenuDia::query()
            ->whereDate('fecha', $fecha)
            ->where('servicio_id', $servicioId)
            ->delete();

        $filas = array_map(static fn (int $pid): array => [
            'fecha'       => $fecha->toDateString(),
            'servicio_id' => $servicioId,
            'producto_id' => $pid,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], array_values(array_unique($productoIds)));

        if ($filas !== []) {
            MenuDia::query()->insert($filas);
        }
    }

    /**
     * Ids de productos ya marcados para una fecha+servicio.
     *
     * @return array<int, int>
     */
    public function seleccionActual(Carbon $fecha, int $servicioId): array
    {
        return MenuDia::query()
            ->whereDate('fecha', $fecha)
            ->where('servicio_id', $servicioId)
            ->pluck('producto_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    // ───────────────────────── Combos del día ─────────────────────────

    /**
     * Combos a mostrar en la pantalla del menú para una fecha y servicio.
     *
     * Tolerancia: si esa fecha NO tiene ningún combo cargado (ningún
     * servicio), se muestran todos los combos activos — así la pantalla
     * nunca queda sin combos por olvidar configurarlos. Si la fecha sí
     * tiene combos pero para otro servicio, este servicio aparece vacío.
     *
     * @return Collection<int, Combo>
     */
    public function combosDisponibles(Carbon $fecha, ?int $servicioId): Collection
    {
        $query = Combo::query()->activo()
            ->orderByRaw("tier = 'res'")
            ->orderBy('complementos');

        if ($this->hayMenuCombosCargado($fecha) && $servicioId !== null) {
            $ids = MenuDiaCombo::query()
                ->whereDate('fecha', $fecha)
                ->where('servicio_id', $servicioId)
                ->pluck('combo_id');

            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /** ¿Esa fecha tiene algún combo del día cargado (cualquier servicio)? */
    public function hayMenuCombosCargado(Carbon $fecha): bool
    {
        return MenuDiaCombo::query()->whereDate('fecha', $fecha)->exists();
    }

    /**
     * Sincroniza los combos de un servicio en una fecha con la lista dada
     * (reemplaza lo anterior para ese fecha+servicio).
     *
     * @param array<int, int> $comboIds
     */
    public function sincronizarCombos(Carbon $fecha, int $servicioId, array $comboIds): void
    {
        MenuDiaCombo::query()
            ->whereDate('fecha', $fecha)
            ->where('servicio_id', $servicioId)
            ->delete();

        $filas = array_map(static fn (int $cid): array => [
            'fecha'       => $fecha->toDateString(),
            'servicio_id' => $servicioId,
            'combo_id'    => $cid,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], array_values(array_unique($comboIds)));

        if ($filas !== []) {
            MenuDiaCombo::query()->insert($filas);
        }
    }

    /**
     * Ids de combos ya marcados para una fecha+servicio.
     *
     * @return array<int, int>
     */
    public function seleccionCombosActual(Carbon $fecha, int $servicioId): array
    {
        return MenuDiaCombo::query()
            ->whereDate('fecha', $fecha)
            ->where('servicio_id', $servicioId)
            ->pluck('combo_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
