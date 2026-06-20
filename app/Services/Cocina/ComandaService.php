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
            'venta_id'          => $venta->id,
            'numero'            => sprintf('C-%05d', $correlativo),
            'tipo'              => $tipo,
            'estado'            => 'pendiente',
            'items'             => $items,
            'cliente_nombre'    => $domicilio['nombre'] ?? null,
            'cliente_telefono'  => $domicilio['telefono'] ?? null,
            'cliente_identidad' => $domicilio['identidad'] ?? null,
            'cliente_direccion' => $domicilio['direccion'] ?? null,
        ]);
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
