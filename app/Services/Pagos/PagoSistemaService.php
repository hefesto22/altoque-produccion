<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Domain\Exceptions\PagoNoMarcableException;
use App\Models\PagoSistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * Pone las cuotas al día con el contrato de config/cobro.php.
     *
     * Idempotente y barato: una sola consulta cuando no hay nada que cambiar,
     * así se puede llamar en cada visita a la pantalla.
     *
     * La regla que gobierna esto: **una cuota PAGADA jamás se reescribe**. Lo
     * que ya se cobró es historia y tiene que seguir diciendo lo que decía el
     * día que entró el dinero. Las cuotas todavía sin pagar sí se actualizan
     * — si se renegoció el trato, lo que viene sigue el trato nuevo. Y si el
     * contrato se acortó, las cuotas sobrantes sin pagar se eliminan.
     *
     * Los cargos extra no se tocan nunca acá: no son parte del contrato.
     *
     * @return int cuántas cuotas nuevas creó
     */
    public function sincronizarPlan(): int
    {
        $cuotas = $this->cuotasDelContrato();

        if ($cuotas === []) {
            return 0;
        }

        /** @var Collection<int, PagoSistema> $existentes */
        $existentes = PagoSistema::query()->delPlan()->get()->keyBy('numero');

        $creadas = 0;

        foreach ($cuotas as $cuota) {
            $fila = $existentes->get($cuota['numero']);

            if ($fila === null) {
                PagoSistema::query()->create([
                    'periodo'  => $cuota['periodo'],
                    'numero'   => $cuota['numero'],
                    'monto'    => $cuota['monto'],
                    'concepto' => $cuota['concepto'],
                    'es_extra' => false,
                ]);

                $creadas++;

                continue;
            }

            if ($fila->pagada) {
                continue;   // historia: no se toca
            }

            $cambio = $fila->periodo->toDateString() !== $cuota['periodo']
                || abs((float) $fila->monto - $cuota['monto']) > 0.001
                || $fila->concepto !== $cuota['concepto'];

            if ($cambio) {
                $fila->update([
                    'periodo'  => $cuota['periodo'],
                    'monto'    => $cuota['monto'],
                    'concepto' => $cuota['concepto'],
                ]);
            }
        }

        // Contrato acortado: las cuotas de más que nadie pagó ya no existen.
        PagoSistema::query()
            ->delPlan()
            ->where('numero', '>', count($cuotas))
            ->where('pagada', false)
            ->delete();

        return $creadas;
    }

    /**
     * Registra un cobro FUERA del contrato: un módulo nuevo, un trabajo
     * aparte. No lleva número de cuota y el plan no lo toca nunca.
     */
    public function agregarCargo(
        string $concepto,
        float $monto,
        Carbon $mes,
        ?string $notas = null,
    ): PagoSistema {
        return PagoSistema::query()->create([
            'periodo'  => $mes->copy()->startOfMonth()->toDateString(),
            'numero'   => null,
            'monto'    => round($monto, 2),
            'concepto' => $concepto,
            'es_extra' => true,
            'notas'    => $notas,
        ]);
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
