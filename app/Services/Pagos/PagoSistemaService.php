<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Domain\Exceptions\PagoNoMarcableException;
use App\Models\PagoSistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El plan de pagos del sistema y su marcado.
 *
 * El contrato vive en `config/cobro.php` (mismo lugar del que se alimenta el
 * aviso mensual del gerente): un solo lugar donde está escrito cuánto y por
 * cuántos meses. Este servicio lo materializa en filas para poder marcarlas
 * pagadas una por una.
 */
final class PagoSistemaService
{
    /**
     * Crea las cuotas que falten según el contrato. Idempotente: se puede
     * llamar en cada visita a la pantalla sin duplicar nada.
     *
     * Las cuotas que YA existen no se tocan aunque cambie la config: el monto
     * pactado de un mes pasado es historia, no configuración.
     *
     * @return int cuántas cuotas nuevas creó
     */
    public function sincronizarPlan(): int
    {
        $cuotas = $this->cuotasDelContrato();

        if ($cuotas === []) {
            return 0;
        }

        // Salida barata: lo normal es que ya esté todo creado.
        if (PagoSistema::query()->count() >= count($cuotas)) {
            return 0;
        }

        $creadas = 0;

        foreach ($cuotas as $cuota) {
            $nueva = PagoSistema::query()->firstOrCreate(
                ['periodo' => $cuota['periodo']],
                [
                    'numero'   => $cuota['numero'],
                    'monto'    => $cuota['monto'],
                    'concepto' => $cuota['concepto'],
                ],
            );

            if ($nueva->wasRecentlyCreated) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Marca una cuota como pagada. Solo la llama el super_admin (lo bloquea
     * la policy), y queda registrado quién y cuándo.
     *
     * @throws PagoNoMarcableException si ya estaba pagada
     */
    public function marcarPagada(
        PagoSistema $cuota,
        float $monto,
        string $formaPago,
        ?string $banco = null,
        ?string $referencia = null,
        ?string $notas = null,
        ?int $usuarioId = null,
    ): PagoSistema {
        return DB::transaction(function () use ($cuota, $monto, $formaPago, $banco, $referencia, $notas, $usuarioId): PagoSistema {
            // Con bloqueo: marcar dos veces desde dos pestañas duplicaría el
            // pago en el resumen.
            $fresca = PagoSistema::query()->whereKey($cuota->id)->lockForUpdate()->firstOrFail();

            if ($fresca->pagada) {
                throw new PagoNoMarcableException('esa cuota ya estaba marcada como pagada.');
            }

            $fresca->update([
                'pagada'       => true,
                'pagada_at'    => now(),
                'monto_pagado' => round($monto, 2),
                'forma_pago'   => $formaPago,
                'banco'        => $banco,
                'referencia'   => $referencia,
                'notas'        => $notas,
                'marcada_por'  => $usuarioId,
            ]);

            activity()
                ->performedOn($fresca)
                ->withProperties([
                    'periodo'    => $fresca->periodo->toDateString(),
                    'monto'      => round($monto, 2),
                    'forma_pago' => $formaPago,
                    'usuario_id' => $usuarioId,
                ])
                ->log('pago_sistema_marcado');

            $cuota->refresh();

            return $fresca;
        });
    }

    /**
     * Deshace el marcado (se marcó el mes equivocado). Lleva motivo: un pago
     * que desaparece sin explicación es exactamente el tipo de cosa que se
     * discute meses después.
     *
     * @throws PagoNoMarcableException si no estaba pagada
     */
    public function revertir(PagoSistema $cuota, string $motivo, ?int $usuarioId = null): PagoSistema
    {
        return DB::transaction(function () use ($cuota, $motivo, $usuarioId): PagoSistema {
            $fresca = PagoSistema::query()->whereKey($cuota->id)->lockForUpdate()->firstOrFail();

            if (! $fresca->pagada) {
                throw new PagoNoMarcableException('esa cuota no estaba marcada como pagada.');
            }

            $anterior = [
                'pagada_at'    => $fresca->pagada_at?->toDateTimeString(),
                'monto_pagado' => $fresca->monto_pagado,
                'forma_pago'   => $fresca->forma_pago,
                'referencia'   => $fresca->referencia,
            ];

            $fresca->update([
                'pagada'       => false,
                'pagada_at'    => null,
                'monto_pagado' => null,
                'forma_pago'   => null,
                'banco'        => null,
                'referencia'   => null,
                'marcada_por'  => null,
            ]);

            activity()
                ->performedOn($fresca)
                ->withProperties(['motivo' => $motivo, 'anterior' => $anterior, 'usuario_id' => $usuarioId])
                ->log('pago_sistema_revertido');

            $cuota->refresh();

            return $fresca;
        });
    }

    /**
     * El contrato de config/cobro.php, mes por mes.
     *
     * @return array<int, array{periodo: string, numero: int, monto: float, concepto: string}>
     */
    private function cuotasDelContrato(): array
    {
        $inicio = Carbon::createFromFormat('Y-m', (string) config('cobro.inicio'))?->startOfMonth();

        if ($inicio === null) {
            return [];
        }

        $inicio = $inicio->startOfDay();

        /** @var array<int, array{meses: int, monto: float, concepto: string}> $etapas */
        $etapas = config('cobro.etapas', []);

        $cuotas = [];
        $numero = 0;

        foreach ($etapas as $etapa) {
            for ($i = 0; $i < (int) $etapa['meses']; $i++) {
                $numero++;

                $cuotas[] = [
                    'periodo'  => $inicio->copy()->addMonthsNoOverflow($numero - 1)->toDateString(),
                    'numero'   => $numero,
                    'monto'    => (float) $etapa['monto'],
                    'concepto' => (string) $etapa['concepto'],
                ];
            }
        }

        return $cuotas;
    }
}
