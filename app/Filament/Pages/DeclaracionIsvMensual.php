<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Exceptions\RestauranteException;
use App\Models\PeriodoFiscal;
use App\Services\Fiscal\DeclaracionIsvService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Declaración ISV mensual (lado ventas). El contador elige el período,
 * carga los totales calculados desde las ventas y, si el mes ya terminó,
 * lo declara (cierre con snapshot inmutable) o lo reabre para rectificar.
 */
class DeclaracionIsvMensual extends Page
{
    protected string $view = 'filament.pages.declaracion-isv-mensual';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Declaración ISV Mensual';
    }

    public static function getNavigationLabel(): string
    {
        return 'Declaración ISV Mensual';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    public static function canAccess(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'contador']);
    }

    public int $anio;

    public int $mes;

    /** @var array<string, float|int>|null */
    public ?array $resumen = null;

    public ?string $estadoPeriodo = null;

    public function mount(): void
    {
        $this->anio = (int) now()->year;
        $this->mes = (int) now()->month;
    }

    public function cargar(): void
    {
        $r = app(DeclaracionIsvService::class)->calcular($this->anio, $this->mes);

        $this->resumen = [
            'cantidad' => $r->cantidadVentas,
            'gravado'  => $r->gravado,
            'exento'   => $r->exento,
            'isv'      => $r->isv,
            'total'    => $r->total,
            'recibos'  => $r->recibosTotal,
            'facturas' => $r->facturasTotal,
            'credito'  => $r->creditoFiscal,
            'a_pagar'  => $r->isvAPagar,
        ];

        $this->estadoPeriodo = PeriodoFiscal::query()
            ->where('anio', $this->anio)->where('mes', $this->mes)->value('estado');
    }

    public function declarar(): void
    {
        try {
            app(DeclaracionIsvService::class)->declarar($this->anio, $this->mes, (int) Auth::id());
        } catch (RestauranteException $e) {
            Notification::make()->title('No se pudo declarar')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Período declarado')->body('Snapshot fiscal registrado.')->success()->send();
        $this->cargar();
    }

    public function reabrir(): void
    {
        app(DeclaracionIsvService::class)->reabrir($this->anio, $this->mes);

        Notification::make()->title('Período reabierto')->body('Habilitado para rectificativa.')->warning()->send();
        $this->cargar();
    }

    public function mesEnCurso(): bool
    {
        return ! Carbon::create($this->anio, $this->mes, 1)->endOfMonth()->isPast();
    }

    /** @return array<int, int> */
    public function getAniosProperty(): array
    {
        $actual = (int) now()->year;

        return range($actual, $actual - 4);
    }

    /** @return array<int, string> */
    public function getMesesProperty(): array
    {
        $meses = [];

        for ($m = 1; $m <= 12; $m++) {
            $meses[$m] = Carbon::create(2000, $m, 1)->translatedFormat('F');
        }

        return $meses;
    }
}
