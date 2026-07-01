<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\Exceptions\VentaSinLineasException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Comanda;
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
        private readonly TicketDiarioService $tickets,
    ) {}

    /**
     * Venta no fiscal: recibo interno con su propio correlativo.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     */
    public function registrarRecibo(array $lineas, int $cajeroId, string $formaPago = 'efectivo', ?string $banco = null, string $tipoOrden = 'local'): Venta
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $formaPago, $banco, $tipoOrden): Venta {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'recibo', formaPago: $formaPago, banco: $banco, tipoOrden: $tipoOrden);

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
    public function registrarFactura(array $lineas, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null, string $tipoOrden = 'local', float $costoViaje = 0): Factura
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $rtn, $nombre, $formaPago, $detallada, $banco, $tipoOrden, $costoViaje): Factura {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'factura', rtn: $rtn, nombre: $nombre, formaPago: $formaPago, banco: $banco, tipoOrden: $tipoOrden, costoViaje: $costoViaje);

            return $this->facturacion->emitirFactura($venta, $rtn, $nombre, $detallada);
        });
    }

    /**
     * Pedido "pagar después": registra la venta como PENDIENTE de pago, sin
     * documento fiscal ni vínculo a un turno. Imprime/genera comanda aparte
     * (la página llama a enviarAComanda). Se cobra luego con cobrarPendiente.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     */
    public function registrarPendiente(array $lineas, int $cajeroId, string $tipoOrden, string $formaPago = 'efectivo', ?string $banco = null, float $costoViaje = 0, ?string $nombreCliente = null): Venta
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $tipoOrden, $formaPago, $banco, $costoViaje, $nombreCliente): Venta {
            // tipo 'recibo' es provisional; al cobrar se emite la factura.
            // El nombre se guarda para identificar el pedido (llevar/domicilio).
            $venta = $this->crearVenta(
                $lineas,
                $cajeroId,
                tipo: 'recibo',
                nombre: $nombreCliente,
                formaPago: $formaPago,
                banco: $banco,
                tipoOrden: $tipoOrden,
                pagada: false,
                costoViaje: $costoViaje,
            );

            $correlativo = (int) DB::selectOne("SELECT nextval('recibos_correlativo_seq') AS n")->n;
            $venta->update(['numero_recibo' => sprintf('R-%08d', $correlativo)]);

            return $venta;
        });
    }

    /**
     * Cobra un pedido pendiente: emite la factura SAR sobre la venta YA
     * existente (no crea otra), la marca pagada y la engancha al turno
     * abierto en que se cobra. La comanda ya se creó al dejarlo pendiente.
     *
     * @throws VentaSinLineasException
     */
    public function cobrarPendiente(Venta $venta, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null): Factura
    {
        return DB::transaction(function () use ($venta, $cajeroId, $rtn, $nombre, $formaPago, $detallada, $banco): Factura {
            $corteId = CorteCaja::query()
                ->where('cajero_id', $cajeroId)
                ->where('estado', 'abierto')
                ->value('id');

            $venta->update([
                'tipo'           => 'factura',
                'forma_pago'     => $formaPago,
                'banco'          => in_array($formaPago, ['tarjeta', 'transferencia'], true) ? $banco : null,
                'rtn_cliente'    => $rtn !== null ? (string) $rtn : null,
                'nombre_cliente' => $nombre,
                'pagada'         => true,
                'pagada_at'      => now(),
                'corte_caja_id'  => $corteId,   // entra al turno donde se cobra
            ]);

            // Pagado = entregado: la comanda sale de la cola de cocina.
            Comanda::query()
                ->where('venta_id', $venta->id)
                ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
                ->update(['estado' => 'entregado', 'entregado_at' => now()]);

            // update() ya refrescó los atributos en memoria; el desglose
            // (gravado/isv/total) no cambia, solo el estado de pago.
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
        string $tipoOrden = 'local',
        bool $pagada = true,
        float $costoViaje = 0,
    ): Venta {
        $resumen = $this->calculador->calcular($lineas);

        // Una venta pagada al momento entra al turno abierto. Una venta
        // PENDIENTE no se vincula a ningún turno todavía: entrará al corte
        // del turno en que efectivamente se cobre (ver cobrarPendiente).
        $corteId = $pagada
            ? CorteCaja::query()
                ->where('cajero_id', $cajeroId)
                ->where('estado', 'abierto')
                ->value('id')
            : null;

        $venta = Venta::create([
            'cajero_id'     => $cajeroId,
            'corte_caja_id' => $corteId,
            'tipo'          => $tipo,
            'tipo_orden'    => $tipoOrden,
            'numero_orden'  => $this->tickets->siguiente($tipoOrden),
            'forma_pago'    => $formaPago,
            // El banco aplica a tarjeta y transferencia (el terminal recibe
            // tarjetas de varios bancos; se concilia por banco en el corte).
            'banco'          => in_array($formaPago, ['tarjeta', 'transferencia'], true) ? $banco : null,
            'rtn_cliente'    => $rtn !== null ? (string) $rtn : null,
            'nombre_cliente' => $nombre,
            'gravado'        => $resumen->gravado,
            'exento'         => $resumen->exento,
            'subtotal_lista' => $resumen->subtotalLista,
            'descuento'      => $resumen->descuento,
            'isv'            => $resumen->isv,
            'total'          => $resumen->total,
            'costo_viaje'    => $costoViaje,        // interno: NO entra al total fiscal
            'pagada'         => $pagada,
            'pagada_at'      => $pagada ? now() : null,
            'vendida_at'     => now(),
        ]);

        $venta->items()->createMany(array_map(
            static fn (LineaVenta $l): array => [
                'producto_id'     => $l->productoId,
                'nombre'          => $l->nombre,
                'precio_unitario' => $l->precioUnitario,
                'precio_lista'    => $l->precioListaUnitario,
                'cantidad'        => $l->cantidad,
                'grava_isv'       => $l->gravaIsv,
                'detalle'         => $l->detalle === [] ? null : $l->detalle,
                'nota'            => $l->nota === '' ? null : $l->nota,
                'componentes'     => $l->componentes === []
                    ? null
                    : array_map(static fn ($c): array => $c->toArray(), $l->componentes),
                'importe'   => $l->importe(),
                'descuento' => $l->descuento(),
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
