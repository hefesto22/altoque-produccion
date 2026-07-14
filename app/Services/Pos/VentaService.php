<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\Exceptions\PagosNoCuadranException;
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
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos Pago mixto (null = un solo método)
     *
     * @throws VentaSinLineasException
     */
    public function registrarRecibo(array $lineas, int $cajeroId, string $formaPago = 'efectivo', ?string $banco = null, string $tipoOrden = 'local', ?array $pagos = null, ?string $nombreOrden = null): Venta
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $formaPago, $banco, $tipoOrden, $pagos, $nombreOrden): Venta {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'recibo', formaPago: $formaPago, banco: $banco, tipoOrden: $tipoOrden, pagos: $pagos, nombreOrden: $nombreOrden);

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
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos Pago mixto (null = un solo método)
     *
     * @throws VentaSinLineasException
     */
    public function registrarFactura(array $lineas, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null, string $tipoOrden = 'local', float $costoViaje = 0, ?array $pagos = null, ?string $nombreOrden = null): Factura
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $rtn, $nombre, $formaPago, $detallada, $banco, $tipoOrden, $costoViaje, $pagos, $nombreOrden): Factura {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'factura', rtn: $rtn, nombre: $nombre, formaPago: $formaPago, banco: $banco, tipoOrden: $tipoOrden, costoViaje: $costoViaje, pagos: $pagos, nombreOrden: $nombreOrden);

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
                nombreOrden: $nombreCliente,
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
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos Pago mixto (null = un solo método)
     *
     * @throws VentaSinLineasException
     */
    public function cobrarPendiente(Venta $venta, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null, ?array $pagos = null): Factura
    {
        return DB::transaction(function () use ($venta, $rtn, $nombre, $formaPago, $detallada, $banco, $pagos): Factura {
            // UNA sola caja: la venta entra al turno abierto del sistema
            // (quién cobró queda en cajero_id).
            $corteId = CorteCaja::query()
                ->where('estado', 'abierto')
                ->value('id');

            // El pago real se define al COBRAR (no al dejar pendiente).
            $normalizado = $this->normalizarPagos((float) $venta->total, $formaPago, $banco, $pagos);

            $venta->update([
                'tipo'           => 'factura',
                'forma_pago'     => $normalizado['forma'],
                'banco'          => $normalizado['forma'] === 'mixto' ? null : $normalizado['filas'][0]['banco'],
                'rtn_cliente'    => $rtn !== null ? (string) $rtn : null,
                'nombre_cliente' => $nombre,
                'pagada'         => true,
                'pagada_at'      => now(),
                'corte_caja_id'  => $corteId,   // entra al turno donde se cobra
            ]);

            // Snapshot de pagos del cobro (el corte de caja suma de aquí).
            $venta->pagos()->delete();
            $venta->pagos()->createMany($normalizado['filas']);

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
     * Corrige la forma de pago de una venta YA registrada (control interno).
     *
     * NO toca el documento fiscal: gravado/exento/ISV/total y el correlativo
     * SAR quedan intactos — por eso no requiere anulación. Reemplaza el
     * snapshot de venta_pagos validando que la suma cuadre al centavo, y
     * deja rastro completo en Activity Log (quién, de qué, a qué).
     *
     * OJO: si el corte de caja de la venta ya está cerrado, su snapshot
     * congelado NO se recalcula (quedó conciliado con los números del
     * momento del cierre). La UI lo advierte antes de confirmar.
     *
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos Pago mixto (null = un solo método)
     *
     * @throws PagosNoCuadranException
     */
    public function corregirPago(Venta $venta, string $formaPago, ?string $banco = null, ?array $pagos = null): Venta
    {
        return DB::transaction(function () use ($venta, $formaPago, $banco, $pagos): Venta {
            $anterior = [
                'forma_pago' => $venta->forma_pago,
                'pagos'      => $venta->pagos()->get(['metodo', 'banco', 'monto'])->toArray(),
            ];

            $normalizado = $this->normalizarPagos((float) $venta->total, $formaPago, $banco, $pagos);

            $venta->pagos()->delete();
            $venta->pagos()->createMany($normalizado['filas']);

            $venta->update([
                'forma_pago' => $normalizado['forma'],
                'banco'      => $normalizado['forma'] === 'mixto' ? null : $normalizado['filas'][0]['banco'],
            ]);

            activity()
                ->performedOn($venta)
                ->withProperties(['antes' => $anterior, 'despues' => [
                    'forma_pago' => $normalizado['forma'],
                    'pagos'      => $normalizado['filas'],
                ]])
                ->log('Corrigió la forma de pago (control interno)');

            return $venta;
        });
    }

    /**
     * Crea la venta y sus items (snapshots) con el desglose calculado.
     *
     * @param array<int, LineaVenta> $lineas
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos
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
        ?array $pagos = null,
        ?string $nombreOrden = null,
    ): Venta {
        $resumen = $this->calculador->calcular($lineas);

        // Los pagos se normalizan y validan ANTES de persistir nada:
        // si no cuadran al centavo, la venta no se crea (fail fast).
        $normalizado = $pagada
            ? $this->normalizarPagos($resumen->total, $formaPago, $banco, $pagos)
            : ['forma' => $formaPago, 'filas' => []];

        // Una venta pagada al momento entra al turno abierto de LA caja
        // (una sola gaveta física; la autoría queda en cajero_id). Una venta
        // PENDIENTE no se vincula a ningún turno todavía: entrará al corte
        // del turno en que efectivamente se cobre (ver cobrarPendiente).
        $corteId = $pagada
            ? CorteCaja::query()
                ->where('estado', 'abierto')
                ->value('id')
            : null;

        $venta = Venta::create([
            'cajero_id'     => $cajeroId,
            'corte_caja_id' => $corteId,
            'tipo'          => $tipo,
            'tipo_orden'    => $tipoOrden,
            'numero_orden'  => $this->tickets->siguiente($tipoOrden),
            'forma_pago'    => $normalizado['forma'],
            // El banco aplica a tarjeta y transferencia (el terminal recibe
            // tarjetas de varios bancos; se concilia por banco en el corte).
            // En mixto el banco vive en cada fila de venta_pagos.
            'banco' => $normalizado['forma'] === 'mixto' || $normalizado['filas'] === []
                ? null
                : $normalizado['filas'][0]['banco'],
            'rtn_cliente'    => $rtn !== null ? (string) $rtn : null,
            'nombre_cliente' => $nombre,
            'nombre_orden'   => $nombreOrden !== null && trim($nombreOrden) !== '' ? mb_strtoupper(trim($nombreOrden)) : null,
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

        if ($normalizado['filas'] !== []) {
            $venta->pagos()->createMany($normalizado['filas']);
        }

        return $venta;
    }

    /**
     * Normaliza los pagos de una venta y garantiza que cuadren con el total.
     *
     * - $pagos null/vacío → un solo pago por el total con $formaPago.
     * - $pagos con filas  → se descartan montos en cero, se valida que la
     *   suma sea exacta (al centavo) y forma_pago resume: 'mixto' si quedan
     *   2+ métodos, o el método único si al final fue uno solo.
     *
     * @param array<int, array{metodo: string, banco?: string|null, monto: float}>|null $pagos
     *
     * @return array{forma: string, filas: array<int, array{metodo: string, banco: string|null, monto: float}>}
     *
     * @throws PagosNoCuadranException
     */
    private function normalizarPagos(float $total, string $formaPago, ?string $banco, ?array $pagos): array
    {
        $total = round($total, 2);

        if ($pagos === null || $pagos === []) {
            return ['forma' => $formaPago, 'filas' => [[
                'metodo' => $formaPago,
                'banco'  => in_array($formaPago, ['tarjeta', 'transferencia'], true) ? $banco : null,
                'monto'  => $total,
            ]]];
        }

        $filas = [];
        $suma = 0.0;

        foreach ($pagos as $pago) {
            $monto = round((float) ($pago['monto'] ?? 0), 2);

            if ($monto <= 0) {
                continue; // método sin monto: no participa
            }

            $filas[] = [
                'metodo' => $pago['metodo'],
                'banco'  => in_array($pago['metodo'], ['tarjeta', 'transferencia'], true)
                    ? ($pago['banco'] ?? null)
                    : null,
                'monto' => $monto,
            ];
            $suma = round($suma + $monto, 2);
        }

        if ($filas === [] || abs($suma - $total) >= 0.01) {
            throw new PagosNoCuadranException($suma, $total);
        }

        return [
            'forma' => count($filas) > 1 ? 'mixto' : $filas[0]['metodo'],
            'filas' => $filas,
        ];
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
