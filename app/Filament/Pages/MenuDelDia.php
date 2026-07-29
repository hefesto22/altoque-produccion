<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Schemas\Components\MontoField;
use App\Models\Combo;
use App\Models\ComboEspecial;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\Tier;
use App\Services\Menu\PlatoDelDiaService;
use App\Services\Pos\MenuDiaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
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

    /** @var array<int, array<string, mixed>> platos especiales de la fecha editada */
    public array $platosDelDia = [];

    public function mount(): void
    {
        $this->fecha = now()->toDateString();
        $this->servicios = Servicio::query()->activos()->get()->all();
        $this->servicioId = Servicio::activoAhora()?->id ?? ($this->servicios[0]['id'] ?? null);

        $this->cargarProductos();
        $this->cargarCombos();
        $this->cargarPlatosDelDia();
        $this->cargarSeleccion();
    }

    /**
     * Botón para crear el especial del día sin salir de esta pantalla: se
     * arma, se le marca qué lleva y queda publicado en los tres servicios
     * de la fecha que se está editando.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('crearPlatoDelDia')
                ->label('Crear plato del día')
                ->icon('heroicon-o-sparkles')
                ->modalHeading('Plato especial del día')
                ->modalDescription(fn (): string => 'Se ofrece solo el '.$this->etiquetaFecha().'. Queda publicado en el POS y en la pantalla del menú de ese día, en los tres servicios — y no aparece ningún otro día.')
                ->modalSubmitActionLabel('Crear y publicar')
                ->modalWidth(Width::TwoExtraLarge)
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('nombre')
                            ->label('Nombre del plato')
                            ->required()->maxLength(120)
                            ->placeholder('Ej: Sopa de caracol')
                            ->columnSpan(2),

                        MontoField::make('precio', 'Precio')->columnSpan(1),
                    ])->columnSpanFull(),

                    CheckboxList::make('productos')
                        ->label('¿Qué lleva?')
                        ->options(fn (): array => self::opcionesDeProductos())
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(3)
                        ->gridDirection('column')
                        ->columnSpanFull()
                        // Sin componentes la cocina recibe solo un nombre y el
                        // POS abre un modal vacío: al menos uno.
                        ->required()
                        ->minItems(1)
                        ->helperText('Lo que marqués es lo que ve la cocina en la comanda, y lo que el cajero puede cambiar en el POS.'),

                    Textarea::make('nota')
                        ->label('Nota para la pantalla (opcional)')
                        ->rows(2)->maxLength(255)
                        ->placeholder('Ej: incluye tortillas y refresco natural')
                        ->columnSpanFull(),

                    Toggle::make('grava_isv')
                        ->label('Grava ISV (15%)')
                        ->default(true)->onColor('success')->inline(false)
                        ->helperText('La comida preparada grava. Apagalo solo si este plato es exento.')
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    abort_unless(Acceso::puede('View:MenuDelDia'), 403);

                    /** @var array<int, int> $ids */
                    $ids = array_map(static fn ($id): int => (int) $id, $data['productos'] ?? []);

                    $plato = app(PlatoDelDiaService::class)->crear(
                        Carbon::parse($this->fecha),
                        (string) $data['nombre'],
                        (float) $data['precio'],
                        $ids,
                        $data['nota'] ?? null,
                        (bool) ($data['grava_isv'] ?? true),
                    );

                    $this->cargarPlatosDelDia();
                    $this->cargarSeleccion();

                    Notification::make()
                        ->title('Plato del día publicado')
                        ->body($plato->nombre.' ya está en el POS y en la pantalla del menú de este día.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Catálogo para marcar qué lleva el plato, ordenado por categoría (no
     * alfabético) y con prefijo visual — mismo criterio que Platillos
     * Completos, para que se vea igual en las dos pantallas.
     *
     * @return array<int, string>
     */
    private static function opcionesDeProductos(): array
    {
        return Producto::query()
            ->activos()
            ->delCatalogo()
            ->where('categoria', '!=', 'combo')
            ->orderByRaw("case categoria when 'proteina' then 1 when 'complemento' then 2 when 'bebida' then 3 when 'extra' then 4 else 5 end")
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria'])
            ->mapWithKeys(static fn (Producto $p): array => [$p->id => match ($p->categoria) {
                'proteina'    => '🍖 '.$p->nombre,
                'complemento' => '🥗 '.$p->nombre,
                'bebida'      => '🥤 '.$p->nombre,
                'extra'       => '➕ '.$p->nombre,
                default       => $p->nombre,
            }])
            ->all();
    }

    /** Platos especiales de la fecha editada, con lo que lleva cada uno. */
    private function cargarPlatosDelDia(): void
    {
        $svc = app(PlatoDelDiaService::class);

        $this->platosDelDia = $svc->delDia(Carbon::parse($this->fecha))
            ->map(static fn (ComboEspecial $p): array => [
                'id'       => (int) $p->id,
                'nombre'   => (string) $p->nombre,
                'precio'   => (float) $p->precio,
                'nota'     => $p->descripcion,
                'desglose' => $p->desglose(),
            ])->all();
    }

    /**
     * Quita el plato del menú del día. Si nunca se vendió desaparece; si ya
     * se cobró queda inactivo (la venta y su ISV no se tocan).
     */
    public function eliminarPlatoDelDia(int $id): void
    {
        abort_unless(Acceso::puede('View:MenuDelDia'), 403);

        $plato = ComboEspecial::query()->delDia(Carbon::parse($this->fecha))->find($id);

        if ($plato === null) {
            return;
        }

        $borrado = app(PlatoDelDiaService::class)->eliminar($plato);

        $this->cargarPlatosDelDia();
        $this->cargarSeleccion();

        Notification::make()
            ->title($borrado ? 'Plato del día eliminado' : 'Plato del día retirado del menú')
            ->body($borrado
                ? 'Ya no aparece en el POS ni en la pantalla.'
                : 'Como ya se vendió, queda guardado para el histórico fiscal pero sale del menú.')
            ->success()
            ->send();
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
        // delCatalogo(): los platos del día NO entran en estas grillas —
        // viven en su propia sección, atados a una sola fecha.
        $productos = Producto::query()->activos()
            ->delCatalogo()
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
        $this->cargarPlatosDelDia();
        $this->cargarSeleccion();
    }

    /** Atajo de los botones Hoy / Mañana / Pasado mañana. */
    public function irAFecha(int $diasDesdeHoy): void
    {
        $this->fecha = now()->addDays($diasDesdeHoy)->toDateString();
        $this->cargarPlatosDelDia();
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
