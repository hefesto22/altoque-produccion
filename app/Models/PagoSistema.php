<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una cuota mensual del contrato del sistema.
 *
 * El plan sale de `config/cobro.php` y se materializa acá (ver
 * PagoSistemaService::sincronizarPlan). Cada fila es un mes: lo pactado, si
 * ya se pagó, cuándo, cómo y quién lo marcó.
 *
 * @property int $id
 * @property Carbon $periodo
 * @property int $numero
 * @property string $monto
 * @property string $concepto
 * @property bool $pagada
 * @property Carbon|null $pagada_at
 * @property string|null $monto_pagado
 * @property string|null $forma_pago
 * @property string|null $banco
 * @property string|null $referencia
 * @property string|null $notas
 * @property int|null $marcada_por
 */
class PagoSistema extends Model
{
    protected $table = 'pagos_sistema';

    /** @var array<int, string> */
    protected $fillable = [
        'periodo', 'numero', 'monto', 'concepto', 'pagada', 'pagada_at',
        'monto_pagado', 'forma_pago', 'banco', 'referencia', 'notas', 'marcada_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'periodo'      => 'date',
            'pagada'       => 'boolean',
            'pagada_at'    => 'datetime',
            'monto'        => 'decimal:2',
            'monto_pagado' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function marcadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marcada_por');
    }

    /**
     * Estado de la cuota, en cuatro colores para leerlo de un vistazo.
     *
     * Una cuota NO se vuelve vencida el mismo día 1: se considera atrasada
     * cuando el mes ya cerró sin que llegara el dinero. Marcar en rojo el
     * mes en curso sería alarma falsa todos los meses.
     */
    public function estado(): string
    {
        if ($this->pagada) {
            return 'pagada';
        }

        $mesActual = now()->startOfMonth();

        return match (true) {
            $this->periodo->lt($mesActual) => 'vencida',
            $this->periodo->eq($mesActual) => 'actual',
            default                        => 'futura',
        };
    }

    /** Etiqueta del estado, tal como la lee el gerente. */
    public function estadoLabel(): string
    {
        return match ($this->estado()) {
            'pagada'  => 'Pagada',
            'vencida' => 'Atrasada',
            'actual'  => 'Toca este mes',
            default   => 'Pendiente',
        };
    }

    /** Color del badge en la tabla. */
    public function estadoColor(): string
    {
        return match ($this->estado()) {
            'pagada'  => 'success',
            'vencida' => 'danger',
            'actual'  => 'warning',
            default   => 'gray',
        };
    }

    /** "Agosto de 2026", con la primera en mayúscula. */
    public function mesLabel(): string
    {
        return ucfirst($this->periodo->translatedFormat('F \d\e Y'));
    }

    /** @param Builder<PagoSistema> $query */
    public function scopePagadas(Builder $query): void
    {
        $query->where('pagada', true);
    }

    /** @param Builder<PagoSistema> $query */
    public function scopePendientes(Builder $query): void
    {
        $query->where('pagada', false);
    }

    /**
     * Cuotas cuyo mes ya cerró sin pago.
     *
     * @param Builder<PagoSistema> $query
     */
    public function scopeAtrasadas(Builder $query): void
    {
        $query->where('pagada', false)->whereDate('periodo', '<', now()->startOfMonth());
    }

    /**
     * Foto del contrato completo.
     *
     * @return array{total: float, pagado: float, saldo: float, cuotas: int, pagadas: int, atrasadas: int, proxima: PagoSistema|null}
     */
    public static function resumen(): array
    {
        $total = round((float) self::query()->sum('monto'), 2);
        // Lo que de verdad entró, no lo pactado: si un mes se pagó de menos,
        // el saldo tiene que reflejarlo.
        $pagado = round((float) self::query()->pagadas()->sum('monto_pagado'), 2);

        return [
            'total'     => $total,
            'pagado'    => $pagado,
            'saldo'     => round($total - $pagado, 2),
            'cuotas'    => self::query()->count(),
            'pagadas'   => self::query()->pagadas()->count(),
            'atrasadas' => self::query()->atrasadas()->count(),
            'proxima'   => self::query()->pendientes()->orderBy('periodo')->first(),
        ];
    }
}
