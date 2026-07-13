<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Combo;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\Tier;
use App\Services\Pos\MenuDiaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Arma el menú del día: para una fecha y un servicio (desayuno/almuerzo/
 * cena), marca qué productos del catálogo se venden. El POS muestra solo
 * lo marcado para el servicio activo.
 */
class MenuDelDia extends Page
{
    protected string $view = 'filament.pages.menu-del-dia';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 3;

    public function getTitle(): string
    {
        return 'Menú del Día';
    }

    public static function getNavigationLabel(): string
    {
        return 'Menú del Día';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Menú';
    }

    public static function canAccess(): bool
    {
        return Acceso::puede('View:MenuDelDia');
    }

    public string $fecha;

    public ?int $servicioId = null;

    /** @var array<int, string> ids marcados (como string desde el checkbox) */
    public array $seleccionados = [];

    /** @var array<int, string> ids de combos marcados (string desde el checkbox) */
    public array $combosSeleccionados = [];

    /** @var array<int, Servicio> */
    public array $servicios = [];

    /** @var array<string, array<int, Producto>> */
    public array $productosPorCategoria = [];

    /** @var array<string, array<int, string>> ids por categoría, como string igual que los checkboxes */
    public array $idsPorCategoria = [];

    /** @var array<int, array<string, mixed>> */
    public array $combos = [];

    public function mount(): void
    {
        $this->fecha = now()->toDateString();
        $this->servicios = Servicio::query()->activos()->get()->all();
        $this->servicioId = Servicio::activoAhora()?->id ?? ($this->servicios[0]['id'] ?? null);

        $this->cargarProductos();
        $this->cargarCombos();
        $this->cargarSeleccion();
    }

    private function cargarCombos(): void
    {
        $mapa = Tier::mapa();

        $this->combos = Combo::query()->activo()
            ->orderBy('tier')
            ->orderBy('complementos')
            ->get()
            ->map(static fn (Combo $c): array => [
                'id'     => $c->id,
                'nombre' => ($mapa[$c->tier] ?? $c->tier).' + '.$c->complementos.' complementos',
                'precio' => (float) $c->precio,
            ])->all();
    }

    private function cargarProductos(): void
    {
        $productos = Producto::query()->activos()
            ->select(['id', 'nombre', 'categoria', 'precio'])
            ->orderBy('nombre')
            ->get();

        $this->productosPorCategoria = [
            'proteina'    => $productos->where('categoria', 'proteina')->values()->all(),
            'complemento' => $productos->where('categoria', 'complemento')->values()->all(),
            'bebida'      => $productos->where('categoria', 'bebida')->values()->all(),
            'extra'       => $productos->where('categoria', 'extra')->values()->all(),
            'combo'       => $productos->where('categoria', 'combo')->values()->all(),
        ];

        $this->idsPorCategoria = array_map(
            static fn (array $items): array => array_map(
                static fn (Producto $p): string => (string) $p->id,
                $items,
            ),
            $this->productosPorCategoria,
        );
    }

    public function cargarSeleccion(): void
    {
        if ($this->servicioId === null) {
            $this->seleccionados = [];
            $this->combosSeleccionados = [];

            return;
        }

        $servicio = new MenuDiaService;
        $fecha = Carbon::parse($this->fecha);

        $this->seleccionados = array_map(
            static fn (int $id): string => (string) $id,
            $servicio->seleccionActual($fecha, $this->servicioId),
        );

        $this->combosSeleccionados = array_map(
            static fn (int $id): string => (string) $id,
            $servicio->seleccionCombosActual($fecha, $this->servicioId),
        );
    }

    /** True si todos los productos de la categoría ya están marcados. */
    public function categoriaCompleta(string $categoria): bool
    {
        $ids = $this->idsPorCategoria[$categoria] ?? [];

        return $ids !== [] && array_diff($ids, $this->seleccionados) === [];
    }

    /**
     * Marca todos los productos de la categoría de un solo clic; si ya
     * estaban todos marcados, los desmarca. Pensado para categorías que
     * suelen estar disponibles completas (ej. bebidas).
     */
    public function alternarCategoria(string $categoria): void
    {
        $ids = $this->idsPorCategoria[$categoria] ?? [];

        if ($ids === []) {
            return;
        }

        $this->seleccionados = $this->categoriaCompleta($categoria)
            ? array_values(array_diff($this->seleccionados, $ids))
            : array_values(array_unique(array_merge($this->seleccionados, $ids)));
    }

    /** True si todos los combos de la pantalla ya están marcados. */
    public function combosCompletos(): bool
    {
        $ids = $this->idsDeCombos();

        return $ids !== [] && array_diff($ids, $this->combosSeleccionados) === [];
    }

    /** Marca todos los combos de un solo clic; si ya estaban todos, los desmarca. */
    public function alternarCombos(): void
    {
        $ids = $this->idsDeCombos();

        if ($ids === []) {
            return;
        }

        $this->combosSeleccionados = $this->combosCompletos()
            ? array_values(array_diff($this->combosSeleccionados, $ids))
            : array_values(array_unique(array_merge($this->combosSeleccionados, $ids)));
    }

    /** @return array<int, string> */
    private function idsDeCombos(): array
    {
        return array_map(static fn (array $c): string => (string) $c['id'], $this->combos);
    }

    public function updatedFecha(): void
    {
        $this->cargarSeleccion();
    }

    /** Atajo de los botones Hoy / Mañana / Pasado mañana. */
    public function irAFecha(int $diasDesdeHoy): void
    {
        $this->fecha = now()->addDays($diasDesdeHoy)->toDateString();
        $this->cargarSeleccion();
    }

    /**
     * Etiqueta legible del día que se está editando, con aviso relativo
     * ("hoy", "mañana") para que quede claro que el menú puede dejarse
     * armado por adelantado.
     */
    public function etiquetaFecha(): string
    {
        $fecha = Carbon::parse($this->fecha);
        $fecha->locale('es'); // setter aparte: encadenado, Larastan tipa el retorno como Carbon|string
        $dias = (int) now()->startOfDay()->diffInDays($fecha->copy()->startOfDay(), false);

        $relativo = match ($dias) {
            0       => 'hoy',
            1       => 'mañana',
            2       => 'pasado mañana',
            -1      => 'ayer',
            default => $dias > 0 ? "en {$dias} días" : abs($dias).' días atrás',
        };

        return $fecha->isoFormat('dddd D [de] MMMM')." — {$relativo}";
    }

    /** True si la fecha editada no es hoy (para resaltar el aviso). */
    public function esOtroDia(): bool
    {
        return ! Carbon::parse($this->fecha)->isToday();
    }

    public function cambiarServicio(int $id): void
    {
        $this->servicioId = $id;
        $this->cargarSeleccion();
    }

    public function guardar(): void
    {
        if ($this->servicioId === null) {
            return;
        }

        $servicio = new MenuDiaService;
        $fecha = Carbon::parse($this->fecha);

        $servicio->sincronizar(
            $fecha,
            $this->servicioId,
            array_map(static fn ($id): int => (int) $id, $this->seleccionados),
        );

        $servicio->sincronizarCombos(
            $fecha,
            $this->servicioId,
            array_map(static fn ($id): int => (int) $id, $this->combosSeleccionados),
        );

        Notification::make()
            ->title('Menú del día guardado')
            ->body(count($this->seleccionados).' producto(s) y '.count($this->combosSeleccionados).' combo(s) en este servicio.')
            ->success()
            ->send();
    }

    public function nombreServicio(): string
    {
        foreach ($this->servicios as $s) {
            if ((int) $s['id'] === $this->servicioId) {
                return (string) $s['nombre'];
            }
        }

        return '';
    }
}
