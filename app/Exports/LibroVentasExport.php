<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Factura;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Libro de Ventas SAR: documentos fiscales (facturas con CAI) emitidos
 * en el período. A diferencia del reporte del contador, solo incluye
 * facturas — los recibos no fiscales no entran al libro formal del SAR.
 *
 * @implements FromQuery<Factura>
 * @implements WithMapping<Factura>
 */
class LibroVentasExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
    ) {}

    /** @return Builder<Factura> */
    public function query(): Builder
    {
        return Factura::query()
            ->select(['id', 'numero', 'rtn_cliente', 'nombre_cliente', 'gravado', 'exento', 'exonerado', 'isv', 'total', 'anulada', 'emitida_at', 'orden_compra_exenta'])
            ->whereBetween('emitida_at', [$this->desde, $this->hasta])
            ->orderBy('numero');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        // La columna de exoneración va AL FINAL a propósito: el contador ya
        // tiene su plantilla armada sobre estas columnas y meterla en medio
        // le correría todas las fórmulas.
        return ['Factura', 'RTN', 'Cliente', 'Exento', 'Gravado 15%', 'ISV', 'Total', 'Estado', 'Fecha emisión', 'Exonerado', 'Orden compra exenta'];
    }

    /**
     * @param Factura $factura
     *
     * @return array<int, string>
     */
    public function map($factura): array
    {
        return [
            $factura->numero,
            $factura->rtn_cliente,
            $factura->nombre_cliente,
            number_format((float) $factura->exento, 2),
            number_format((float) $factura->gravado, 2),
            number_format((float) $factura->isv, 2),
            number_format((float) $factura->total, 2),
            $factura->anulada ? 'ANULADA' : 'Vigente',
            $factura->emitida_at->format('d/m/Y H:i'),
            number_format((float) $factura->exonerado, 2),
            $factura->orden_compra_exenta ?? '',
        ];
    }
}
