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
     *
     * $efectivoContado null = cierre automático (nadie contó la gaveta):
     * la diferencia queda null y el corte se marca "por revisar" hasta
     * que un administrador lo corrija.
     */
    public function cerrar(CorteCaja $corte, ?float $efectivoContado, ?string $notas = null, bool $automatico = false): CorteCaja
    {
        return DB::transaction(function () use ($corte, $efectivoContado, $notas, $automatico): CorteCaja {
            // cuentaEnCaja: fuera pendientes por cobrar Y ventas con factura
            // anulada (su dinero se devolvió o se recobró en la corregida).
            $fila = Venta::query()
                ->where('corte_caja_id', $corte->id)
                ->cuentaEnCaja()
                ->selectRaw('
                    count(*) as cantidad,
                    coalesce(sum(total), 0) as total,
                    coalesce(sum(isv), 0) as isv
                ')
                ->first();

            // Por método se suma desde venta_pagos: con pago mixto una venta
            // reparte su total entre varios métodos y el efectivo esperado
            // en gaveta debe contar SOLO la porción en efectivo.
            $pagos = DB::selectOne("
                SELECT
                    coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'efectivo'), 0)      AS efectivo,
                    coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'tarjeta'), 0)       AS tarjeta,
                    coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'transferencia'), 0) AS transferencia
                FROM venta_pagos vp
                JOIN ventas v ON v.id = vp.venta_id
                WHERE v.corte_caja_id = ? AND v.pagada = true
                  AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id AND f.anulada = true)
            ", [$corte->id]);

            $totalEfectivo = (float) ($pagos->efectivo ?? 0);
            $esperado = (float) $corte->fondo_inicial + $totalEfectivo;

            // Nuevo saldo del terminal POS: lo que traía al abrir + lo que
            // entró por tarjeta y transferencia en el turno.
            $terminalFinal = round(
                (float) $corte->fondo_terminal + (float) ($pagos->tarjeta ?? 0) + (float) ($pagos->transferencia ?? 0),
                2,
            );

            $corte->update([
                'estado'              => 'cerrado',
                'cerrado_at'          => now(),
                'cantidad_ventas'     => (int) ($fila->cantidad ?? 0),
                'total_ventas'        => (float) ($fila->total ?? 0),
                'total_isv'           => (float) ($fila->isv ?? 0),
                'total_efectivo'      => $totalEfectivo,
                'total_tarjeta'       => (float) ($pagos->tarjeta ?? 0),
                'total_transferencia' => (float) ($pagos->transferencia ?? 0),
                'terminal_final'      => $terminalFinal,
                'cierre_automatico'   => $automatico,
                'efectivo_contado'    => $efectivoContado,
                'diferencia'          => $efectivoContado !== null ? round($efectivoContado - $esperado, 2) : null,
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
