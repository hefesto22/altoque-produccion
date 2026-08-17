<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Combo;
use App\Models\MenuDia;
use App\Models\MenuDiaCombo;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Resuelve qué productos del catálogo están disponibles para una fecha y
 * servicio, según el menú del día.
 *
 * Regla de tolerancia: si esa fecha NO tiene ningún menú cargado, el POS
 * muestra todo el catálogo activo (no se bloquea la caja). Si la fecha sí
 * tiene menú pero para otro servicio, ese servicio aparece vacío.
 *
 * Los platos del día (productos con `fecha_especial`) quedan siempre fuera
 * salvo en su propia fecha, donde entran siempre — no se marcan en menu_dia
 * ni dependen de él.
 */
final class MenuDiaService
{
    /** Prefijo de la caché del menú del POS: una entrada por fecha. */
    private const CACHE_POS = 'pos.menu.';

    /**
     * Menú del POS agrupado por sección, en ARRAYS PLANOS y cacheado.
     *
     * Por qué arrays y no modelos: esto alimenta propiedades computadas de
     * la página de Livewire. Un modelo Eloquent no se serializa — Livewire
     * guarda clase+id y lo vuelve a traer de la base en CADA request. Con el
     * menú viviendo en propiedades públicas, el POS disparaba una consulta
     * por producto en cada toque de botón, antes de hacer nada de lo que se
     * le había pedido. Eso era el segundo de espera por acción.
     *
     * Por qué cacheado: el catálogo no cambia entre clic y clic. Se guarda
     * una entrada por fecha (el plato del día depende de la fecha) y muere
     * sola al terminar el día o cuando alguien toca un producto
     * (App\Observers\MenuPosObserver).
     *
     * @return array{proteinas: list<array<string, mixed>>, complementos: list<array<string, mixed>>, bebidas: list<array<string, mixed>>, extras: list<array<string, mixed>>, combos: list<array<string, mixed>>, platosDelDia: list<array<string, mixed>>}
     */
    public function paraElPos(Carbon $fecha): array
    {
        /** @var array{proteinas: list<array<string, mixed>>, complementos: list<array<string, mixed>>, bebidas: list<array<string, mixed>>, extras: list<array<string, mixed>>, combos: list<array<string, mixed>>, platosDelDia: list<array<string, mixed>>} $menu */
        $menu = Cache::remember(
            self::CACHE_POS.$fecha->toDateString(),
            $fecha->copy()->endOfDay(),
            function () use ($fecha): array {
                $productos = $this->disponibles($fecha, null);

                $seccion = static fn (string $categoria): array => $productos
                    ->where('categoria', $categoria)
                    ->map(static fn (Producto $p): array => [
                        'id'             => (int) $p->id,
                        'nombre'         => (string) $p->nombre,
                        'precio'         => (float) $p->precio,
                        'grava_isv'      => (bool) $p->grava_isv,
                        'tier_combo'     => $p->tier_combo,
                        'descripcion'    => $p->descripcion,
                        'fecha_especial' => $p->fecha_especial?->toDateString(),
                    ])
                    ->values()
                    ->all();

                $combos = collect($seccion('combo'));

                return [
                    'proteinas'    => $seccion('proteina'),
                    'complementos' => $seccion('complemento'),
                    'bebidas'      => $seccion('bebida'),
                    'extras'       => $seccion('extra'),
                    // El especial del día va en su propia sección, arriba de
                    // todo: es lo primero que pregunta el cliente y lo único
                    // que la cocina hizo solo hoy.
                    'platosDelDia' => $combos->whereNotNull('fecha_especial')->values()->all(),
                    'combos'       => $combos->whereNull('fecha_especial')->values()->all(),
                ];
            },
        );

        return $menu;
    }

    /**
     * El catálogo cambió: que el POS rearme el menú de hoy.
     *
     * Solo aplica a `productos` (precio, nombre, activo, plato del día). Las
     * filas de `menu_dia` no entran acá: el POS pide el menú con servicio
     * null y en ese caso `disponibles()` ni las mira.
     */
    public static function olvidarCachePos(): void
    {
        Cache::forget(self::CACHE_POS.now()->toDateString());
    }

    /**
     * @return Collection<int, Producto>
     */
    public function disponibles(Carbon $fecha, ?int $servicioId): Collection
    {
        $query = Producto::query()->vendibles()->activos()
            ->disponibleEn($fecha)
            ->select(['id', 'nombre', 'categoria', 'tier_combo', 'descripcion', 'precio', 'grava_isv', 'fecha_especial'])
            ->orderBy('nombre');

        if ($this->hayMenuCargado($fecha) && $servicioId !== null) {
            $ids = MenuDia::query()
                ->whereDate('fecha', $fecha)
                ->where('servicio_id', $servicioId)
                ->pluck('producto_id');

            // El plato del día entra SIEMPRE, sin pasar por menu_dia: se
            // ofrece el día entero y su publicación es `fecha_especial`.
            // Si dependiera de filas en menu_dia, crear un plato marcaría la
            // fecha como "con menú cargado" y tumbaría la tolerancia de
            // arriba — el resto del menú desaparecería de golpe.
            $query->where(function (Builder $q) use ($ids, $fecha): void {
                $q->whereIn('id', $ids)
                    ->orWhere(function (Builder $especial) use ($fecha): void {
                        $especial->whereNotNull('fecha_especial')->whereDate('fecha_especial', $fecha);
                    });
            });
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
