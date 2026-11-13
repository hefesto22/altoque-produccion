<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\BrandingSetting;
use App\Models\Combo;
use App\Models\ComboEspecial;
use App\Models\EmpresaSetting;
use App\Models\Servicio;
use App\Models\Tier;
use App\Services\Pos\MenuDiaService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Pantalla pública de menú (menu board para la TV del local). Muestra el
 * menú del servicio elegido en el formato del flyer. El personal elige el
 * servicio y bloquea la pantalla para que los clientes no la toquen.
 */
#[Layout('layouts.pantalla')]
class MenuPantalla extends Component
{
    public ?int $servicioId = null;

    /** @var array<int, Servicio> */
    public array $servicios = [];

    public function mount(): void
    {
        $this->servicios = Servicio::query()->activos()->get()->all();
        $this->servicioId = Servicio::activoAhora()?->id ?? ($this->servicios[0]['id'] ?? null);
    }

    public function setServicio(int $id): void
    {
        $this->servicioId = $id;
    }

    public function render(): View
    {
        $menuDia = app(MenuDiaService::class);
        $disponibles = $menuDia->disponibles(now(), $this->servicioId);

        $proteinas = $disponibles->where('categoria', 'proteina')->values();
        $complementos = $disponibles->where('categoria', 'complemento')->values();
        $bebidas = $disponibles->where('categoria', 'bebida')->values();

        // Combos especiales disponibles hoy, con su composición desglosada.
        $idsCombos = $disponibles->where('categoria', 'combo')->pluck('id')->all();
        $combosEspeciales = $idsCombos === []
            ? collect()
            : ComboEspecial::query()
                ->with(['proteinaCombo:id,nombre', 'items.producto:id,nombre'])
                ->whereIn('id', $idsCombos)
                ->orderBy('nombre')
                ->get()
                ->map(fn (ComboEspecial $c): array => [
                    'nombre'   => $c->nombre,
                    'precio'   => (float) $c->precio,
                    'desglose' => $c->desglose($c->proteinaCombo?->nombre),
                    'nota'     => $c->descripcion,
                    'del_dia'  => $c->esDelDia(),
                ]);

        // En la TV la pleca de los tiers se cambia por "ó" ("POLLO Ó CERDO");
        // solo cambia lo que se pinta, el nombre del tier en la BD sigue igual
        // para el POS y los reportes.
        $tiers = Tier::mapa();
        $tierMapa = array_map(
            static fn (string $nombre): string => (string) preg_replace('#\s*/\s*#u', ' ó ', $nombre),
            $tiers,
        );

        // Precios individuales por nivel (tier) de proteína presente hoy.
        $individuales = [];

        foreach ($proteinas->whereNotNull('tier_combo')->groupBy('tier_combo') as $codigo => $grupo) {
            $linea = self::lineaIndividual($tiers[$codigo] ?? (string) $codigo, $grupo->pluck('precio')->all());

            if ($linea !== null) {
                $individuales[] = $linea;
            }
        }

        if ($complementos->isNotEmpty()) {
            $individuales[] = 'COMPLEMENTOS L.'.number_format((float) $complementos->first()->precio, 0);
        }

        // Combos del día para este servicio, en el formato del flyer.
        $combos = $menuDia->combosDisponibles(now(), $this->servicioId)
            ->map(fn (Combo $c): string => sprintf(
                '%s + %d COMPLEMENTOS L.%s',
                mb_strtoupper($tierMapa[$c->tier] ?? $c->tier),
                $c->complementos,
                number_format((float) $c->precio, 2),
            ))->all();

        $e = EmpresaSetting::actual();

        // Texto escrito a mano en Datos de la Empresa → Pantalla de menú. Si
        // está, manda: el negocio anuncia sus precios como quiere (pollo L.60 y
        // cerdo L.70 aunque compartan nivel de combo) sin tocar productos ni
        // niveles. Vacío: la línea se calcula de los productos del día.
        $porcionesTexto = trim((string) $e->menu_porciones_texto);

        if ($porcionesTexto !== '') {
            $individuales = [mb_strtoupper($porcionesTexto)];
        }

        $logoPath = BrandingSetting::current()->logo_path;

        // Qué servicio se está mostrando. La pantalla recuerda el último
        // elegido (localStorage) y al bloquearla la barra desaparece: sin
        // esto es imposible saber si estás viendo Desayuno o Almuerzo, y
        // parece que "el menú no se actualiza" cuando en realidad se editó otro.
        $servicioNombre = collect($this->servicios)
            ->first(fn ($s): bool => (int) $s->id === $this->servicioId)?->nombre;

        return view('livewire.menu-pantalla', [
            'servicioNombre'   => $servicioNombre,
            'proteinas'        => $proteinas,
            'complementos'     => $complementos,
            'bebidas'          => $bebidas,
            'individuales'     => $individuales,
            'combos'           => $combos,
            'combosEspeciales' => $combosEspeciales,
            'fecha'            => Carbon::now()->translatedFormat('l j \d\e F Y'),
            'empresa'          => [
                'nombre'            => $e->nombreMostrar(),
                'telefono'          => $e->telefono,
                'direccion'         => $e->direccion,
                'formas_pago_texto' => $e->formas_pago_texto,
                'horario'           => $e->horario,
            ],
            'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'navidad' => self::esTemporadaNavidad(Carbon::now()),
        ]);
    }

    /**
     * Línea de PORCIONES INDIVIDUALES para un nivel de proteína.
     *
     * Un tier como "Pollo / Cerdo" es UN solo nivel con un solo precio, pero
     * en la TV se anuncia cada carne con su precio: "POLLO L.60 Ó CERDO L.60"
     * (pedido de Mauricio, 2026-11-11). En los combos sigue como
     * "POLLO Ó CERDO + 2 COMPLEMENTOS".
     *
     * El precio es el más bajo del grupo, pero sin los que están en 0: eso es
     * un producto mal cargado, no una oferta, y la TV anunciaba "L.0" (pasó
     * con POLLO EN CREMA DE HONGOS). Si todos están en 0 no sale la línea.
     *
     * @param iterable<mixed> $precios
     */
    public static function lineaIndividual(string $nombreTier, iterable $precios): ?string
    {
        $validos = [];

        foreach ($precios as $precio) {
            if (is_numeric($precio) && (float) $precio > 0) {
                $validos[] = (float) $precio;
            }
        }

        if ($validos === []) {
            return null;
        }

        $monto = ' L.'.number_format(min($validos), 0);
        $partes = preg_split('#\s*/\s*#u', trim($nombreTier)) ?: [$nombreTier];

        return implode(' Ó ', array_map(
            static fn (string $parte): string => mb_strtoupper($parte).$monto,
            $partes,
        ));
    }

    /**
     * Temporada navideña: del 1 de noviembre al 6 de enero (Día de Reyes),
     * ambos incluidos. La pantalla se viste sola y el 7 de enero vuelve a la
     * normalidad sin deploy (pedido de Mauricio, 2026-11-11).
     */
    public static function esTemporadaNavidad(Carbon $hoy): bool
    {
        return $hoy->month >= 11 || ($hoy->month === 1 && $hoy->day <= 6);
    }
}
