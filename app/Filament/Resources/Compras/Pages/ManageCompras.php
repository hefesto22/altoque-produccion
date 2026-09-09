<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Models\Compra;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Lista de compras con el desglose diario arriba.
 *
 * El control del contador es por día ("el lunes fueron L. 3,400, el martes
 * L. 1,200"), así que sobre la tabla va una tarjeta por cada día del mes con
 * movimiento. Tocar una tarjeta enciende el filtro `dia` de la tabla —el
 * mismo que se puede poner a mano desde el panel de filtros—, así que la
 * lista de abajo siempre muestra exactamente lo que dice la tarjeta.
 */
class ManageCompras extends ManageRecords
{
    protected static string $resource = CompraResource::class;

    /** La página del panel más el desglose diario encima de la tabla. */
    protected string $view = 'filament.compras.manage-compras';

    /** Mes que muestran las tarjetas, en formato Y-m. */
    public string $mesDesglose = '';

    /** Día elegido en el calendario del desglose, en formato Y-m-d. */
    public ?string $diaCalendario = null;

    public function mount(): void
    {
        parent::mount();

        // Si se entra con un día ya filtrado (link compartido o recarga), el
        // desglose abre en el mes de ese día y no en el mes en curso.
        $dia = $this->diaFiltrado();

        $this->mesDesglose = $dia !== null
            ? Carbon::parse($dia)->format('Y-m')
            : now()->format('Y-m');

        $this->diaCalendario = $dia;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar compra')
                ->mutateDataUsing(function (array $data): array {
                    $data['registrado_por'] = Auth::id();

                    return $data;
                }),
        ];
    }

    // ── Desglose por día ────────────────────────────────────────────────

    /** El día que la tabla está filtrando ahora mismo, si hay alguno. */
    public function diaFiltrado(): ?string
    {
        $dia = $this->tableFilters['dia']['valor'] ?? null;

        return is_string($dia) && $dia !== '' ? $dia : null;
    }

    /**
     * Tocar una tarjeta filtra ese día; tocar la que ya está encendida apaga
     * el filtro y se vuelve a ver el mes completo.
     */
    public function verDia(string $fecha): void
    {
        $this->escribirFiltroDia($this->diaFiltrado() === $fecha ? null : $fecha);
    }

    public function verTodoElMes(): void
    {
        $this->escribirFiltroDia(null);
    }

    /**
     * Calendario del desglose: elegir una fecha filtra ese día y mueve las
     * tarjetas al mes al que pertenece, aunque sea de otro mes. Es la vía
     * para llegar a un día suelto sin ir mes por mes.
     */
    public function updatedDiaCalendario(): void
    {
        $dia = ($this->diaCalendario !== null && $this->diaCalendario !== '')
            ? $this->diaCalendario
            : null;

        if ($dia !== null) {
            $this->mesDesglose = Carbon::parse($dia)->format('Y-m');
        }

        $this->escribirFiltroDia($dia);
    }

    private function escribirFiltroDia(?string $fecha): void
    {
        $filtros = $this->tableFilters ?? [];
        $filtros['dia']['valor'] = $fecha;

        $this->tableFilters = $filtros;

        // El calendario sigue a las tarjetas: si se tocó una, queda esa
        // fecha adentro; si se apagó el filtro, queda vacío.
        $this->diaCalendario = $fecha;

        // Lo mismo que hace Filament cuando el filtro se toca a mano: guarda
        // el estado (incluso si los filtros son diferidos) y manda la tabla a
        // la primera página.
        $this->updatedTableFilters();
    }

    public function mesAnterior(): void
    {
        $this->mesDesglose = $this->mesMostrado()->subMonthNoOverflow()->format('Y-m');
    }

    public function mesSiguiente(): void
    {
        // No se navega al futuro: no hay compras con fecha adelantada.
        if ($this->esMesActual()) {
            return;
        }

        $this->mesDesglose = $this->mesMostrado()->addMonthNoOverflow()->format('Y-m');
    }

    /** Primer día del mes que se está mostrando. */
    public function mesMostrado(): Carbon
    {
        // El mes en curso como red: la propiedad se llena en mount(), pero
        // una fecha vacía haría reventar el parse.
        $mes = $this->mesDesglose !== '' ? $this->mesDesglose : now()->format('Y-m');

        return Carbon::parse($mes.'-01')->startOfMonth();
    }

    public function esMesActual(): bool
    {
        return $this->mesDesglose === now()->format('Y-m');
    }

    /** "Septiembre 2026" */
    public function tituloMes(): string
    {
        return ucfirst($this->mesMostrado()->translatedFormat('F Y'));
    }

    /**
     * Lo gastado cada día del mes mostrado, del más reciente al más viejo.
     * Solo salen los días con movimiento: un día sin compras no es una
     * tarjeta en cero, simplemente no está.
     *
     * @return array<int, array{fecha: string, largo: string, dia: string, semana: string, hoy: bool, compras: int, total: float, isv: float}>
     */
    public function getDesgloseProperty(): array
    {
        $mes = $this->mesMostrado();

        return Compra::query()
            ->whereBetween('fecha', [
                $mes->toDateString(),
                $mes->copy()->endOfMonth()->toDateString(),
            ])
            ->toBase()
            // El ISV de un recibo ya se guarda en cero (Compra::booted), pero
            // el CASE lo deja explícito: solo la factura acredita crédito.
            ->selectRaw("fecha, COUNT(*) AS compras, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(CASE WHEN tipo_documento = 'factura' THEN isv ELSE 0 END), 0) AS isv")
            ->groupBy('fecha')
            ->orderByDesc('fecha')
            ->get()
            ->map(function ($fila): array {
                $fecha = Carbon::parse((string) $fila->fecha);

                return [
                    'fecha'   => $fecha->toDateString(),
                    'largo'   => $fecha->format('d/m/Y'),
                    'dia'     => $fecha->format('d'),
                    'semana'  => rtrim(ucfirst($fecha->translatedFormat('D')), '.'),
                    'hoy'     => $fecha->isToday(),
                    'compras' => (int) $fila->compras,
                    'total'   => (float) $fila->total,
                    'isv'     => (float) $fila->isv,
                ];
            })
            ->all();
    }

    /**
     * Totales del mes. Se suman de las mismas tarjetas que se ven arriba,
     * así no pueden discrepar de ellas.
     *
     * @param array<int, array{fecha: string, largo: string, dia: string, semana: string, hoy: bool, compras: int, total: float, isv: float}> $dias
     *
     * @return array{dias: int, compras: int, total: float, isv: float}
     */
    public function totalesDe(array $dias): array
    {
        return [
            'dias'    => count($dias),
            'compras' => (int) array_sum(array_column($dias, 'compras')),
            'total'   => (float) array_sum(array_column($dias, 'total')),
            'isv'     => (float) array_sum(array_column($dias, 'isv')),
        ];
    }
}
