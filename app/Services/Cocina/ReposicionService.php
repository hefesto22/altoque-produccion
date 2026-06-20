<?php

declare(strict_types=1);

namespace App\Services\Cocina;

use App\Models\AlertaReposicion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Alertas de reposición de complementos del buffet. Garantiza una sola
 * alerta activa por producto (constraint parcial en BD + verificación).
 */
final class ReposicionService
{
    /** Levanta una alerta de reposición para el producto (idempotente). */
    public function alertar(int $productoId, ?int $usuarioId = null): AlertaReposicion
    {
        $activa = AlertaReposicion::query()
            ->where('producto_id', $productoId)
            ->where('estado', 'activa')
            ->first();

        if ($activa !== null) {
            return $activa;
        }

        return AlertaReposicion::create([
            'producto_id' => $productoId,
            'estado'      => 'activa',
            'creada_por'  => $usuarioId,
        ]);
    }

    /** Marca como repuesta la alerta activa del producto, si existe. */
    public function reponer(int $productoId, ?int $usuarioId = null): void
    {
        AlertaReposicion::query()
            ->where('producto_id', $productoId)
            ->where('estado', 'activa')
            ->update([
                'estado'       => 'repuesta',
                'repuesta_por' => $usuarioId,
                'repuesta_at'  => now(),
            ]);
    }

    /** @return Collection<int, AlertaReposicion> */
    public function activas(): Collection
    {
        return AlertaReposicion::query()->activas()->with('producto:id,nombre')->get();
    }

    /** @return array<int, int> ids de productos con alerta activa */
    public function productosConAlerta(): array
    {
        return AlertaReposicion::query()
            ->where('estado', 'activa')
            ->pluck('producto_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
