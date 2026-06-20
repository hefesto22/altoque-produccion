<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Período fiscal mensual de la declaración ISV.
 *
 * Mientras está 'abierto' los totales se recalculan en vivo desde las
 * ventas. Al 'declarar' se congela el snapshot (gravado/exento/isv/total)
 * y queda inmutable hasta que se reabra para una rectificativa.
 *
 * @property int $id
 * @property int $anio
 * @property int $mes
 * @property string $estado
 * @property float $gravado
 * @property float $exento
 * @property float $isv
 * @property float $total
 * @property float $recibos_total
 * @property float $facturas_total
 * @property int $cantidad_ventas
 * @property int|null $declarado_por
 * @property Carbon|null $declarado_at
 */
class PeriodoFiscal extends Model
{
    protected $table = 'periodos_fiscales';

    /** @var array<int, string> */
    protected $fillable = [
        'anio',
        'mes',
        'estado',
        'gravado',
        'exento',
        'isv',
        'credito_fiscal',
        'isv_a_pagar',
        'total',
        'recibos_total',
        'facturas_total',
        'cantidad_ventas',
        'declarado_por',
        'declarado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anio'            => 'integer',
            'mes'             => 'integer',
            'gravado'         => 'decimal:2',
            'exento'          => 'decimal:2',
            'isv'             => 'decimal:2',
            'credito_fiscal'  => 'decimal:2',
            'isv_a_pagar'     => 'decimal:2',
            'total'           => 'decimal:2',
            'recibos_total'   => 'decimal:2',
            'facturas_total'  => 'decimal:2',
            'cantidad_ventas' => 'integer',
            'declarado_at'    => 'datetime',
        ];
    }

    public function estaDeclarado(): bool
    {
        return $this->estado === 'declarado';
    }

    public function etiqueta(): string
    {
        return Carbon::create($this->anio, $this->mes, 1)->translatedFormat('F Y');
    }

    /** @return BelongsTo<User, $this> */
    public function declarante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declarado_por');
    }
}
