<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\Exceptions\VentaSinLineasException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\CorteCaja;
use App\Models\Factura;
use App\Models\Venta;
use App\Services\Facturacion\FacturacionSarService;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el registro de una venta. La página de Filament solo llama
 * a estos métodos; toda la regla de negocio vive aquí.
 *
 * Regla de oro: TODA venta se persiste con su desglose de ISV completo,
 * sea recibo no fiscal o factura SAR. La diferencia es solo si se emite
 * documento fiscal con correlativo.
 */
final class VentaService
{
    public function __construct(
        private readonly CalculaImpuestos $calculador,
        private readonly FacturacionSarService $facturacion,
    ) {}

    /**
     * Venta no fiscal: recibo interno con su propio correlativo.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     */
    public function registrarRecibo(array $lineas, int $cajeroId, string $formaPago = 'efectivo', ?string $banco = null): Venta
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $formaPago, $banco): Venta {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'recibo', formaPago: $formaPago, banco: $banco);

            // Correlativo interno desde la secuencia Postgres (atómico).
            $correlativo = (int) DB::selectOne(
                "SELECT nextval('recibos_correlativo_seq') AS n"
            )->n;

            $venta->update(['numero_recibo' => sprintf('R-%08d', $correlativo)]);

            return $venta;
        });
    }

    /**
     * Venta fiscal: registra la venta y emite factura SAR con
     * correlativo bajo lock. Si no hay CAI activo, la excepción sube
     * y la caja puede ofrecer recibo en su lugar.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     */
    public function registrarFactura(array $lineas, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null): Factura
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $rtn, $nombre, $formaPago, $detallada, $banco): Factura {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'factura', rtn: $rtn, nombre: $nombre, formaPago: $formaPago, banco: $banco);

            return $this->facturacion->emitirFactura($venta, $rtn, $nombre, $detallada);
        });
    }

    /**
     * Crea la venta y sus items (snapshots) con el desglose calculado.
     *
     * @param array<int, LineaVenta> $lineas
     */
    private function crearVenta(
        array $lineas,
        int $cajeroId,
        string $tipo,
        ?RTN $rtn = null,
        ?string $nombre = null,
        string $formaPago = 'efectivo',
        ?string $banco = null,
    ): Venta {
        $resumen = $this->calculador->calcular($lineas);

        // Vincula la venta al turno de caja abierto del cajero (si hay).
        $corteId = CorteCaja::query()
            ->where('cajero_id', $cajeroId)
            ->where('estado', 'abierto')
            ->value('id');

        $venta = Venta::create([
            'cajero_id'      => $cajeroId,
            'corte_caja_id'  => $corteId,
            'tipo'           => $tipo,
            'forma_pago'     => $formaPago,
            'banco'          => $formaPago === 'transferencia' ? $banco : null,
            'rtn_cliente'    => $rtn !== null ? (string) $rtn : null,
            'nombre_cliente' => $nombre,
            'gravado'        => $resumen->gravado,
            'exento'         => $resumen->exento,
            'isv'            => $resumen->isv,
            'total'          => $resumen->total,
            'vendida_at'     => now(),
        ]);

        $venta->items()->createMany(array_map(
            static fn (LineaVenta $l): array => [
                'producto_id'     => $l->productoId,
                'nombre'          => $l->nombre,
                'precio_unitario' => $l->precioUnitario,
                'cantidad'        => $l->cantidad,
                'grava_isv'       => $l->gravaIsv,
                'detalle'         => $l->detalle === [] ? null : $l->detalle,
                'importe'         => $l->importe(),
            ],
            $lineas,
        ));

        return $venta;
    }

    /**
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     */
    private function guardarContraVacio(array $lineas): void
    {
        if ($lineas === []) {
            throw new VentaSinLineasException;
        }
    }
}
