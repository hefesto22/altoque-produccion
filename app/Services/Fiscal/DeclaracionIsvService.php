<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Domain\Exceptions\PeriodoNoFinalizadoException;
use App\Domain\Exceptions\PeriodoYaDeclaradoException;
use App\Domain\ValueObjects\ResumenPeriodo;
use App\Models\Compra;
use App\Models\PeriodoFiscal;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Declaración ISV mensual (lado ventas).
 *
 * Calcula el débito fiscal del período por agregación SQL sobre las
 * ventas (nunca sumando en PHP) y permite cerrar el período con un
 * snapshot inmutable. El cierre es transaccional y bloquea declarar un
 * mes que aún no termina.
 *
 * El ISV del período es el de TODAS las ventas (recibo o factura), ya
 * que toda venta guarda su desglose para que el contador declare.
 */
final class DeclaracionIsvService
{
    /**
     * Totales del período calculados en vivo desde las ventas.
     * No persiste nada (CQRS: query).
     */
    public function calcular(int $anio, int $mes): ResumenPeriodo
    {
        [$desde, $hasta] = $this->rango($anio, $mes);

        // Agregación en SQL, una sola query, columnas explícitas.
        $fila = Venta::query()
            ->whereBetween('vendida_at', [$desde, $hasta])
            ->selectRaw('
                count(*) as cantidad,
                coalesce(sum(gravado), 0) as gravado,
                coalesce(sum(exento), 0) as exento,
                coalesce(sum(isv), 0) as isv,
                coalesce(sum(total), 0) as total,
                coalesce(sum(total) filter (where tipo = \'recibo\'), 0) as recibos_total,
                coalesce(sum(total) filter (where tipo = \'factura\'), 0) as facturas_total
            ')
            ->first();

        // Crédito fiscal: ISV de las compras gravadas del período.
        $credito = (float) Compra::query()
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('isv');

        $debito = (float) ($fila->isv ?? 0);

        return new ResumenPeriodo(
            anio: $anio,
            mes: $mes,
            cantidadVentas: (int) ($fila->cantidad ?? 0),
            gravado: (float) ($fila->gravado ?? 0),
            exento: (float) ($fila->exento ?? 0),
            isv: $debito,
            total: (float) ($fila->total ?? 0),
            recibosTotal: (float) ($fila->recibos_total ?? 0),
            facturasTotal: (float) ($fila->facturas_total ?? 0),
            creditoFiscal: round($credito, 2),
            isvAPagar: round($debito - $credito, 2),
        );
    }

    /**
     * Cierra el período: congela el snapshot y lo marca declarado.
     *
     * @throws PeriodoNoFinalizadoException si el mes aún no termina
     * @throws PeriodoYaDeclaradoException si el período ya está cerrado
     */
    public function declarar(int $anio, int $mes, int $usuarioId): PeriodoFiscal
    {
        if (! $this->mesFinalizado($anio, $mes)) {
            throw new PeriodoNoFinalizadoException($anio, $mes);
        }

        return DB::transaction(function () use ($anio, $mes, $usuarioId): PeriodoFiscal {
            $periodo = PeriodoFiscal::query()
                ->where('anio', $anio)
                ->where('mes', $mes)
                ->lockForUpdate()
                ->first()
                ?? new PeriodoFiscal(['anio' => $anio, 'mes' => $mes]);

            if ($periodo->estaDeclarado()) {
                throw new PeriodoYaDeclaradoException($anio, $mes);
            }

            $resumen = $this->calcular($anio, $mes);

            $periodo->fill([
                'estado'          => 'declarado',
                'gravado'         => $resumen->gravado,
                'exento'          => $resumen->exento,
                'isv'             => $resumen->isv,
                'credito_fiscal'  => $resumen->creditoFiscal,
                'isv_a_pagar'     => $resumen->isvAPagar,
                'total'           => $resumen->total,
                'recibos_total'   => $resumen->recibosTotal,
                'facturas_total'  => $resumen->facturasTotal,
                'cantidad_ventas' => $resumen->cantidadVentas,
                'declarado_por'   => $usuarioId,
                'declarado_at'    => now(),
            ]);
            $periodo->save();

            return $periodo;
        });
    }

    /**
     * Reabre un período declarado para preparar una rectificativa
     * (Acuerdo SAR 189-2014). Los totales se recalcularán en vivo.
     */
    public function reabrir(int $anio, int $mes): PeriodoFiscal
    {
        $periodo = PeriodoFiscal::query()
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->firstOrFail();

        $periodo->update([
            'estado'        => 'abierto',
            'declarado_por' => null,
            'declarado_at'  => null,
        ]);

        return $periodo;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(int $anio, int $mes): array
    {
        $desde = Carbon::create($anio, $mes, 1)->startOfMonth();

        return [$desde, $desde->copy()->endOfMonth()];
    }

    /** El mes ya terminó (no es el mes en curso ni uno futuro). */
    private function mesFinalizado(int $anio, int $mes): bool
    {
        return Carbon::create($anio, $mes, 1)->endOfMonth()->isPast();
    }
}
