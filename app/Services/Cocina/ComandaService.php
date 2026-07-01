<?php

declare(strict_types=1);

namespace App\Services\Cocina;

use App\Models\Comanda;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona las comandas de cocina (pedidos para llevar / a domicilio).
 * El correlativo de comanda sale de una secuencia Postgres atómica.
 */
final class ComandaService
{
    /**
     * Crea la comanda a partir de una venta ya registrada. Solo recibe los
     * items que van a cocina (subconjunto de la venta, para pedidos mixtos).
     *
     * @param array<int, array{nombre: string, cantidad: int, detalle: array<int, string>}> $items
     * @param array{nombre?: string, telefono?: string, identidad?: string, direccion?: string} $domicilio
     */
    public function crear(Venta $venta, string $tipo, array $items, array $domicilio = []): Comanda
    {
        $correlativo = (int) DB::selectOne("SELECT nextval('comandas_correlativo_seq') AS n")->n;

        return Comanda::create([
            'venta_id' => $venta->id,
            'numero'   => sprintf('C-%05d', $correlativo),
            'tipo'     => $tipo,
            // Nace en "preparando": la cocina empieza al toque, sin paso previo.
            'estado'            => 'preparando',
            'items'             => $items,
            'cliente_nombre'    => $domicilio['nombre'] ?? null,
            'cliente_telefono'  => $domicilio['telefono'] ?? null,
            'cliente_identidad' => $domicilio['identidad'] ?? null,
            'cliente_direccion' => $domicilio['direccion'] ?? null,
        ]);
    }

    /**
     * Cambia el tipo de entrega de una comanda: 'llevar' (el cliente pasa a
     * recogerlo) ↔ 'domicilio' (lo lleva un repartidor). No toca el estado de
     * cocina (sigue preparándose igual). Al pasar a domicilio se completan los
     * datos de entrega; al volver a llevar se conservan por si se revierte.
     *
     * @param array{nombre?: string, telefono?: string, identidad?: string, direccion?: string} $datos
     */
    public function cambiarTipo(Comanda $comanda, string $tipo, array $datos = [], float $costoViaje = 0): Comanda
    {
        $cambios = ['tipo' => $tipo];

        if ($tipo === 'domicilio') {
            $cambios += array_filter([
                'cliente_nombre'    => $datos['nombre'] ?? null,
                'cliente_telefono'  => $datos['telefono'] ?? null,
                'cliente_identidad' => $datos['identidad'] ?? null,
                'cliente_direccion' => $datos['direccion'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        $comanda->update($cambios);

        // La venta refleja el tipo y el costo de viaje: a 'llevar' se quita el
        // viaje; a 'domicilio' se coloca el monto indicado.
        $comanda->venta?->update([
            'tipo_orden'  => $tipo,
            'costo_viaje' => $tipo === 'domicilio' ? round($costoViaje, 2) : 0,
        ]);

        return $comanda;
    }

    public function marcarPreparando(Comanda $comanda): Comanda
    {
        $comanda->update(['estado' => 'preparando']);

        return $comanda;
    }

    public function marcarListo(Comanda $comanda): Comanda
    {
        $comanda->update(['estado' => 'listo', 'listo_at' => now()]);

        return $comanda;
    }

    public function marcarEntregado(Comanda $comanda): Comanda
    {
        $comanda->update(['estado' => 'entregado', 'entregado_at' => now()]);

        return $comanda;
    }
}
