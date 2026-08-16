<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\Exceptions\CuentaNoAmpliableException;
use App\Domain\Exceptions\PagosNoCuadranException;
use App\Domain\Exceptions\PedidoNoAnulableException;
use App\Domain\Exceptions\VentaSinLineasException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Comanda;
use App\Models\CorteCaja;
use App\Models\CuentaPrepago;
use App\Models\Factura;
use App\Models\Venta;
use App\Services\Cuentas\CuentaPrepagoService;
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
        private readonly CuentaPrepagoService $cuentas,
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
    public function registrarFactura(array $lineas, int $cajeroId, ?RTN $rtn, string $nombre, string $formaPago = 'efectivo', ?bool $detallada = null, ?string $banco = null, string $tipoOrden = 'local', float $costoViaje = 0, ?array $pagos = null, ?string $nombreOrden = null, ?CuentaPrepago $cuentaSaldo = null): Factura
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($lineas, $cajeroId, $rtn, $nombre, $formaPago, $detallada, $banco, $tipoOrden, $costoViaje, $pagos, $nombreOrden, $cuentaSaldo): Factura {
            $venta = $this->crearVenta($lineas, $cajeroId, tipo: 'factura', rtn: $rtn, nombre: $nombre, formaPago: $formaPago, banco: $banco, tipoOrden: $tipoOrden, costoViaje: $costoViaje, pagos: $pagos, nombreOrden: $nombreOrden);

            // El saldo se descuenta ANTES de emitir la factura, a propósito:
            // el correlativo SAR sale de una secuencia de Postgres y un
            // rollback NO lo devuelve. Si el saldo no alcanzara, esta
            // transacción muere sin haber quemado un número de factura.
            $this->cobrarConSaldo($venta, $cuentaSaldo, $cajeroId);

            return $this->facturacion->emitirFactura($venta, $rtn, $nombre, $detallada);
        });
    }

    /**
     * Descuenta de la cuenta prepago la porción de la venta pagada con saldo.
     *
     * El movimiento queda atado a la venta: un descuento sin factura detrás
     * es un agujero por donde se va la plata del cliente.
     */
    private function cobrarConSaldo(Venta $venta, ?CuentaPrepago $cuenta, int $cajeroId): void
    {
        if ($cuenta === null) {
            return;
        }

        $monto = round((float) $venta->pagos()->where('metodo', 'saldo')->sum('monto'), 2);

        if ($monto <= 0) {
            return;
        }

        $this->cuentas->consumir($cuenta, $monto, $venta, $cajeroId);
    }

    /**
     * Anula un pedido que nunca se cobró: el cliente se fue, el mesero se
     * equivocó, el pedido no se hizo. Se MARCA, no se borra — queda quién y
     * cuándo para poder revisarlo después.
     *
     * Una vez anulada, `scopePendientes()` la deja fuera: si el pedido no se
     * hizo, no hay nada que cobrar.
     *
     * @throws PedidoNoAnulableException si ya se cobró o ya estaba anulada
     */
    public function anularPendiente(Venta $venta, int $usuarioId, ?string $motivo = null): Venta
    {
        return DB::transaction(function () use ($venta, $usuarioId, $motivo): Venta {
            // Recarga con bloqueo: dos cajas no pueden anular y cobrar a la vez.
            $fresca = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if ($fresca->pagada) {
                throw new PedidoNoAnulableException('ya se cobró, para deshacerlo hay que anular la factura.');
            }

            if ($fresca->anulada) {
                throw new PedidoNoAnulableException('ya estaba anulado.');
            }

            $fresca->update([
                'anulada'          => true,
                'anulada_at'       => now(),
                'anulada_por'      => $usuarioId,
                'motivo_anulacion' => $motivo,
            ]);

            activity()
                ->performedOn($fresca)
                ->withProperties(['motivo' => $motivo, 'usuario_id' => $usuarioId])
                ->log('pedido_anulado');

            return $fresca;
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
     * Agrega líneas a una cuenta ABIERTA (pedido pendiente de pago): el
     * cliente pidió otra bebida o se le olvidó algo y lo pide después.
     *
     * Se suman a la MISMA venta, no se crea otra: al cobrar sale UNA sola
     * factura con todo, que es lo que el cliente espera y lo que cuadra
     * fiscalmente. Mientras el pedido está pendiente no se consumió ningún
     * correlativo CAI, así que la venta todavía se puede tocar.
     *
     * El desglose se SUMA en vez de recalcularse desde cero: CalculadorVenta
     * acumula línea por línea redondeando cada una, así que sumar el resumen
     * de lo nuevo da EXACTAMENTE lo mismo que recalcular todas las líneas
     * juntas — cuadra al centavo y no hay que rehidratar los items viejos.
     *
     * `vendida_at` no se toca: la cuenta conserva su lugar en la fila de
     * "Pedidos por cobrar", que se ordena por hora de apertura.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @throws VentaSinLineasException
     * @throws CuentaNoAmpliableException si ya se cobró o está anulada
     */
    public function agregarACuenta(Venta $venta, array $lineas, int $usuarioId): Venta
    {
        $this->guardarContraVacio($lineas);

        return DB::transaction(function () use ($venta, $lineas, $usuarioId): Venta {
            // Bloqueo: la caja puede estar cobrando esta misma cuenta en el
            // mismo segundo desde otra pantalla. O se agrega, o se cobra.
            $fresca = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if ($fresca->pagada) {
                throw new CuentaNoAmpliableException('ya se cobró — cobrá lo nuevo como pedido aparte.');
            }

            if ($fresca->anulada) {
                throw new CuentaNoAmpliableException('ese pedido está anulado.');
            }

            $resumen = $this->calculador->calcular($lineas);

            $fresca->items()->createMany($this->filasDeItems($lineas));

            $fresca->update([
                'gravado'        => round((float) $fresca->gravado + $resumen->gravado, 2),
                'exento'         => round((float) $fresca->exento + $resumen->exento, 2),
                'isv'            => round((float) $fresca->isv + $resumen->isv, 2),
                'total'          => round((float) $fresca->total + $resumen->total, 2),
                'subtotal_lista' => round((float) $fresca->subtotal_lista + $resumen->subtotalLista, 2),
                'descuento'      => round((float) $fresca->descuento + $resumen->descuento, 2),
            ]);

            // Que una cuenta abierta crezca es sensible (el cliente paga al
            // final): queda quién agregó qué y en cuánto quedó el total.
            activity()
                ->performedOn($fresca)
                ->withProperties([
                    'usuario_id' => $usuarioId,
                    'agregado'   => round($resumen->total, 2),
                    'total'      => round((float) $fresca->total, 2),
                    'items'      => array_map(
                        static fn (LineaVenta $l): string => $l->cantidad.'x '.$l->nombre,
                        array_values($lineas),
                    ),
                ])
                ->log('cuenta_ampliada');

            return $fresca;
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

        $venta->items()->createMany($this->filasDeItems($lineas));

        if ($normalizado['filas'] !== []) {
            $venta->pagos()->createMany($normalizado['filas']);
        }

        return $venta;
    }

    /**
     * Filas de `venta_items` (snapshots congelados) a partir de las líneas.
     * Lo comparten la venta nueva y la ampliación de una cuenta abierta.
     *
     * @param array<int, LineaVenta> $lineas
     *
     * @return array<int, array<string, mixed>>
     */
    private function filasDeItems(array $lineas): array
    {
        return array_map(
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
        );
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
