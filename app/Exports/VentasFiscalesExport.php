<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Reporte que el contador usa para declarar manualmente: todas las
 * ventas del período con su desglose, separando recibo de factura.
 *
 * @implements FromQuery<Venta>
 * @implements WithMapping<Venta>
 */
class VentasFiscalesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
    ) {}

    /** @return Builder<Venta> */
    public function query(): Builder
    {
        return Venta::query()
            ->select(['id', 'tipo', 'numero_recibo', 'rtn_cliente', 'gravado', 'exento', 'isv', 'total', 'vendida_at'])
            ->with('factura:id,venta_id,numero') // evita N+1 al mapear el número de factura
            ->whereBetween('vendida_at', [$this->desde, $this->hasta])
            ->orderBy('vendida_at');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['#', 'Tipo', 'Documento', 'RTN', 'Gravado', 'Exento', 'ISV', 'Total', 'Fecha'];
    }

    /**
     * @param Venta $venta
     *
     * @return array<int, string>
     */
    public function map($venta): array
    {
        return [
            (string) $venta->id,
            ucfirst($venta->tipo),
            $venta->tipo === 'factura' ? ($venta->factura?->numero ?? '—') : ($venta->numero_recibo ?? '—'),
            $venta->rtn_cliente ?? '—',
            number_format((float) $venta->gravado, 2),
            number_format((float) $venta->exento, 2),
            number_format((float) $venta->isv, 2),
            number_format((float) $venta->total, 2),
            $venta->vendida_at->format('d/m/Y H:i'),
        ];
    }
}
