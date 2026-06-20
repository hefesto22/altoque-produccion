<?php

declare(strict_types=1);

namespace App\Services\Pedidos;

use App\Domain\ValueObjects\LineaVenta;
use App\Models\PedidoOnline;
use App\Services\Cocina\ComandaService;
use App\Services\Pos\VentaService;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos online: alta desde la web (pendiente), confirmación por el
 * personal (genera venta + comanda) y rechazo.
 */
final class PedidoOnlineService
{
    public function __construct(
        private readonly VentaService $ventas,
        private readonly ComandaService $comandas,
    ) {}

    /**
     * Crea un pedido pendiente desde la web.
     *
     * @param array<string, mixed> $datos
     * @param array<int, array{producto_id: int, nombre: string, precio: float, cantidad: int, grava_isv: bool, detalle: array<int, string>}> $items
     */
    public function crear(array $datos, array $items, float $total): PedidoOnline
    {
        $correlativo = (int) DB::selectOne("SELECT nextval('pedidos_online_seq') AS n")->n;

        return PedidoOnline::create([
            'numero'            => sprintf('P-%05d', $correlativo),
            'tipo'              => $datos['tipo'],
            'estado'            => 'pendiente',
            'cliente_nombre'    => $datos['nombre'],
            'cliente_telefono'  => $datos['telefono'],
            'cliente_identidad' => $datos['identidad'] ?? null,
            'cliente_direccion' => $datos['direccion'] ?? null,
            'notas'             => $datos['notas'] ?? null,
            'items'             => $items,
            'total'             => $total,
            'metodo_pago'       => $datos['metodo_pago'] ?? 'efectivo',
            'comprobante_path'  => $datos['comprobante_path'] ?? null,
        ]);
    }

    /**
     * Confirma el pedido: registra la venta (recibo) y manda la comanda a
     * cocina. Todo en una transacción.
     */
    public function confirmar(PedidoOnline $pedido, int $cajeroId): PedidoOnline
    {
        return DB::transaction(function () use ($pedido, $cajeroId): PedidoOnline {
            $lineas = array_map(static fn (array $i): LineaVenta => new LineaVenta(
                productoId: (int) $i['producto_id'],
                nombre: (string) $i['nombre'],
                precioUnitario: (float) $i['precio'],
                cantidad: (int) $i['cantidad'],
                gravaIsv: (bool) $i['grava_isv'],
                detalle: $i['detalle'] ?? [],
            ), $pedido->items);

            $venta = $this->ventas->registrarRecibo($lineas, $cajeroId, $pedido->metodo_pago);

            // Snapshot para la comanda (solo lo que cocina necesita).
            $itemsComanda = array_map(static fn (array $i): array => [
                'nombre'   => $i['nombre'],
                'cantidad' => $i['cantidad'],
                'detalle'  => $i['detalle'] ?? [],
            ], $pedido->items);

            $this->comandas->crear(
                $venta,
                $pedido->tipo === 'domicilio' ? 'domicilio' : 'llevar',
                $itemsComanda,
                [
                    'nombre'    => $pedido->cliente_nombre,
                    'telefono'  => $pedido->cliente_telefono,
                    'identidad' => $pedido->cliente_identidad,
                    'direccion' => $pedido->cliente_direccion,
                ],
            );

            $pedido->update([
                'estado'         => 'confirmado',
                'venta_id'       => $venta->id,
                'confirmado_por' => $cajeroId,
                'confirmado_at'  => now(),
            ]);

            return $pedido;
        });
    }

    public function rechazar(PedidoOnline $pedido, string $motivo): PedidoOnline
    {
        $pedido->update(['estado' => 'rechazado', 'motivo_rechazo' => $motivo]);

        return $pedido;
    }
}
