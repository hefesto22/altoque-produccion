<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AvisoPago;
use Illuminate\Support\Carbon;

/**
 * Cuándo y cuánto hay que cobrar del sistema, según el contrato que vive en
 * `config/cobro.php`.
 *
 * Regla acordada (2026-08-01): el aviso aparece el **día 1 a las 5:00 p.m.**
 * y se queda hasta que el gerente lo marque como recibido o hasta que termine
 * el mes. No es un cron: se calcula al renderizar, así que no depende de que
 * el scheduler haya corrido ni se pierde si el servidor estaba caído.
 */
final class CobroMensual
{
    /** Primer día del mes al que corresponde el cobro de esa fecha. */
    public static function periodo(Carbon $ahora): Carbon
    {
        return $ahora->copy()->startOfMonth()->startOfDay();
    }

    /**
     * Monto del mes, o null si ese mes queda fuera del contrato (antes del
     * inicio o después de la última etapa).
     */
    public static function monto(Carbon $periodo): ?float
    {
        $etapa = self::etapaDe($periodo);

        return $etapa === null ? null : (float) $etapa['monto'];
    }

    /** Concepto del mes ("Sistema", "Servidor"), o null si no aplica. */
    public static function concepto(Carbon $periodo): ?string
    {
        $etapa = self::etapaDe($periodo);

        return $etapa === null ? null : (string) $etapa['concepto'];
    }

    /**
     * ¿Toca mostrar el aviso ahora?
     *
     * El día 1 antes de la hora acordada NO se muestra: el trato es que
     * aparezca a las 5 p.m., no a las 12 de la noche.
     */
    public static function toca(Carbon $ahora): bool
    {
        if (self::monto(self::periodo($ahora)) === null) {
            return false;
        }

        if ($ahora->day > 1) {
            return true;
        }

        return $ahora->hour >= (int) config('cobro.hora_aviso', 17);
    }

    /** ¿El gerente ya marcó como recibido el recordatorio de este mes? */
    public static function yaConfirmado(Carbon $periodo): bool
    {
        return AvisoPago::query()->whereDate('periodo', $periodo)->exists();
    }

    /**
     * Etapa del contrato que cubre ese mes.
     *
     * @return array{meses: int, monto: float, concepto: string}|null
     */
    private static function etapaDe(Carbon $periodo): ?array
    {
        $inicio = Carbon::createFromFormat('Y-m', (string) config('cobro.inicio'))?->startOfMonth();

        if ($inicio === null) {
            return null;
        }

        // Meses transcurridos desde el primer mes cobrado (0 = el primero).
        $transcurridos = ($periodo->year - $inicio->year) * 12 + ($periodo->month - $inicio->month);

        if ($transcurridos < 0) {
            return null;
        }

        $acumulado = 0;

        /** @var array<int, array{meses: int, monto: float, concepto: string}> $etapas */
        $etapas = config('cobro.etapas', []);

        foreach ($etapas as $etapa) {
            $acumulado += (int) $etapa['meses'];

            if ($transcurridos < $acumulado) {
                return $etapa;
            }
        }

        // Contrato terminado: el aviso deja de salir solo.
        return null;
    }
}
