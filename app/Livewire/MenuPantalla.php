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
                ]);

        // Precios individuales por nivel (tier) de proteína presente hoy.
        $tierMapa = Tier::mapa();
        $individuales = [];

        foreach ($proteinas->whereNotNull('tier_combo')->groupBy('tier_combo') as $codigo => $grupo) {
            $nombre = $tierMapa[$codigo] ?? $codigo;
            $individuales[] = mb_strtoupper($nombre).' L.'.number_format((float) $grupo->min('precio'), 0);
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
        $logoPath = BrandingSetting::current()->logo_path;

        return view('livewire.menu-pantalla', [
            'proteinas'        => $proteinas,
            'complementos'     => $complementos,
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
        ]);
    }
}
