<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Compra;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Libro de Compras SAR: facturas de compra del período con su crédito
 * fiscal (ISV), para la declaración mensual. Los recibos de compra (sin
 * factura del proveedor) NO se listan: no acreditan ISV.
 *
 * @implements FromQuery<Compra>
 * @implements WithMapping<Compra>
 */
class LibroComprasExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
    ) {}

    /** @return Builder<Compra> */
    public function query(): Builder
    {
        // Solo FACTURAS: el Libro de Compras respalda el crédito fiscal, y un
        // recibo de compra no acredita ISV (queda como gasto en el panel).
        return Compra::query()
            ->acreditaIsv()
            ->select(['id', 'fecha', 'numero_factura', 'proveedor_nombre', 'proveedor_rtn', 'exento', 'gravado', 'isv', 'total'])
            ->whereBetween('fecha', [$this->desde, $this->hasta])
            ->orderBy('fecha');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Fecha', 'Factura', 'Proveedor', 'RTN', 'Exento', 'Gravado 15%', 'ISV (crédito)', 'Total'];
    }

    /**
     * @param Compra $compra
     *
     * @return array<int, string>
     */
    public function map($compra): array
    {
        return [
            $compra->fecha->format('d/m/Y'),
            $compra->numero_factura ?? '—',
            $compra->proveedor_nombre,
            $compra->proveedor_rtn ?? '—',
            number_format((float) $compra->exento, 2),
            number_format((float) $compra->gravado, 2),
            number_format((float) $compra->isv, 2),
            number_format((float) $compra->total, 2),
        ];
    }
}
