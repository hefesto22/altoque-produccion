<?php

declare(strict_types=1);

namespace App\Services\Caja;

use App\Models\Comanda;
use App\Models\CorteCaja;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Apertura y cierre de turno de caja. El cierre concilia el efectivo
 * contado contra el esperado (fondo + ventas en efectivo) y congela el
 * snapshot del turno por agregación SQL.
 */
final class CorteCajaService
{
    /** Turno abierto del cajero, o null si no tiene. */
    public function abierto(int $cajeroId): ?CorteCaja
    {
        return CorteCaja::query()
            ->where('cajero_id', $cajeroId)
            ->where('estado', 'abierto')
            ->first();
    }

    /** Abre un turno con su fondo de efectivo y el saldo inicial del terminal POS. */
    public function abrir(int $cajeroId, float $fondoInicial, float $fondoTerminal = 0): CorteCaja
    {
        $existente = $this->abierto($cajeroId);

        if ($existente !== null) {
            return $existente;
        }

        return CorteCaja::create([
            'cajero_id'      => $cajeroId,
            'fondo_inicial'  => $fondoInicial,
            'fondo_terminal' => $fondoTerminal,
            'estado'         => 'abierto',
            'abierto_at'     => now(),
        ]);
    }

    /**
     * Cierra el turno: calcula totales por agregación SQL, registra el
     * efectivo contado y la diferencia, y deja el snapshot inmutable.
     */
    public function cerrar(CorteCaja $corte, float $efectivoContado, ?string $notas = null): CorteCaja
    {
        return DB::transaction(function () use ($corte, $efectivoContado, $notas): CorteCaja {
            $fila = Venta::query()
                ->where('corte_caja_id', $corte->id)
                ->where('pagada', true)   // los pendientes por cobrar no cuentan
                ->selectRaw("
                    count(*) as cantidad,
                    coalesce(sum(total), 0) as total,
                    coalesce(sum(isv), 0) as isv,
                    coalesce(sum(total) filter (where forma_pago = 'efectivo'), 0) as efectivo,
                    coalesce(sum(total) filter (where forma_pago = 'tarjeta'), 0) as tarjeta,
                    coalesce(sum(total) filter (where forma_pago = 'transferencia'), 0) as transferencia
                ")
                ->first();

            $totalEfectivo = (float) ($fila->efectivo ?? 0);
            $esperado = (float) $corte->fondo_inicial + $totalEfectivo;

            $corte->update([
                'estado'              => 'cerrado',
                'cerrado_at'          => now(),
                'cantidad_ventas'     => (int) ($fila->cantidad ?? 0),
                'total_ventas'        => (float) ($fila->total ?? 0),
                'total_isv'           => (float) ($fila->isv ?? 0),
                'total_efectivo'      => $totalEfectivo,
                'total_tarjeta'       => (float) ($fila->tarjeta ?? 0),
                'total_transferencia' => (float) ($fila->transferencia ?? 0),
                'efectivo_contado'    => $efectivoContado,
                'diferencia'          => round($efectivoContado - $esperado, 2),
                'notas'               => $notas,
            ]);

            // Cierre de día: la pantalla de cocina se vacía (todo lo que quede
            // en cola se marca entregado).
            Comanda::query()
                ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
                ->update(['estado' => 'entregado', 'entregado_at' => now()]);

            return $corte;
        });
    }
}
