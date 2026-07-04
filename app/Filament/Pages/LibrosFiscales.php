<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exports\LibroComprasExport;
use App\Exports\LibroVentasExport;
use App\Exports\VentasFiscalesExport;
use App\Support\Acceso;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Libros fiscales SAR — generados on-demand sobre los documentos del
 * período. No requieren que el período esté declarado (útil para
 * conciliaciones antes del cierre).
 */
class LibrosFiscales extends Page
{
    protected string $view = 'filament.pages.libros-fiscales';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 3;

    public function getTitle(): string
    {
        return 'Libros Fiscales';
    }

    public static function getNavigationLabel(): string
    {
        return 'Libros Fiscales';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    public static function canAccess(): bool
    {
        return Acceso::puede('View:LibrosFiscales');
    }

    public int $anio;

    public int $mes;

    public function mount(): void
    {
        $this->anio = (int) now()->year;
        $this->mes = (int) now()->month;
    }

    public function descargarLibroVentas(): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango();

        return (new LibroVentasExport($desde, $hasta))
            ->download("libro-ventas-{$this->anio}-{$this->mes}.xlsx");
    }

    public function descargarLibroCompras(): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango();

        return (new LibroComprasExport($desde, $hasta))
            ->download("libro-compras-{$this->anio}-{$this->mes}.xlsx");
    }

    public function descargarReporteContador(): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango();

        return (new VentasFiscalesExport($desde, $hasta))
            ->download("reporte-ventas-{$this->anio}-{$this->mes}.xlsx");
    }

    /** @return array{0: string, 1: string} */
    private function rango(): array
    {
        $desde = Carbon::create($this->anio, $this->mes, 1)->startOfMonth();

        return [$desde->toDateTimeString(), $desde->copy()->endOfMonth()->toDateTimeString()];
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
