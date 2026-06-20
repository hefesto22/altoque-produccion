<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CaiFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Rango de correlativos autorizado por SAR (CAI).
 *
 * El consumo del correlativo SIEMPRE ocurre bajo lockForUpdate dentro
 * de la transacción de FacturacionSarService — este modelo solo expone
 * helpers de lectura y formateo, nunca incrementa fuera del lock.
 *
 * @property int $id
 * @property string $codigo
 * @property string $establecimiento
 * @property string $punto_emision
 * @property string $tipo_documento
 * @property int $correlativo_desde
 * @property int $correlativo_hasta
 * @property int $correlativo_actual
 * @property Carbon $fecha_autorizacion
 * @property Carbon $fecha_limite_emision
 * @property string $estado
 */
class Cai extends Model
{
    /** @use HasFactory<CaiFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'codigo',
        'establecimiento',
        'punto_emision',
        'tipo_documento',
        'correlativo_desde',
        'correlativo_hasta',
        'correlativo_actual',
        'fecha_autorizacion',
        'fecha_limite_emision',
        'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'correlativo_desde'    => 'integer',
            'correlativo_hasta'    => 'integer',
            'correlativo_actual'   => 'integer',
            'fecha_autorizacion'   => 'date',
            'fecha_limite_emision' => 'date',
        ];
    }

    /**
     * Al activar un CAI, desactiva los demás del mismo tipo de documento.
     * Garantiza que la facturación nunca encuentre dos CAI activos del
     * mismo tipo (la query con lockForUpdate espera uno solo).
     */
    protected static function booted(): void
    {
        static::saved(static function (Cai $cai): void {
            if ($cai->estado === 'activo' && $cai->wasChanged('estado')) {
                static::query()
                    ->where('tipo_documento', $cai->tipo_documento)
                    ->where('id', '!=', $cai->id)
                    ->where('estado', 'activo')
                    ->update(['estado' => 'inactivo']);
            }
        });
    }

    /** @return HasMany<Factura, $this> */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /** Prefijo SAR Est-Punto-Tipo, ej: 001-001-01. */
    public function prefijo(): string
    {
        return "{$this->establecimiento}-{$this->punto_emision}-{$this->tipo_documento}";
    }

    /** @phpstan-impure depende de correlativo_actual, que cambia al emitir cada factura. */
    public function rangoAgotado(): bool
    {
        return $this->correlativo_actual >= $this->correlativo_hasta;
    }

    /**
     * Calcula el siguiente correlativo SIN persistir. La persistencia
     * (increment + save) la hace el service dentro del lock.
     */
    public function siguienteCorrelativo(): int
    {
        $proximo = $this->correlativo_actual + 1;

        return max($proximo, $this->correlativo_desde);
    }

    /** Número fiscal formateado: establecimiento-punto-tipo-correlativo(8). */
    public function formatearNumero(int $correlativo): string
    {
        return sprintf(
            '%s-%s-%s-%08d',
            $this->establecimiento,
            $this->punto_emision,
            $this->tipo_documento,
            $correlativo,
        );
    }

    /**
     * @param Builder<Cai> $query
     *
     * @return Builder<Cai>
     */
    public function scopeActivo(Builder $query): Builder
    {
        return $query->where('estado', 'activo')
            ->whereDate('fecha_limite_emision', '>=', now());
    }
}
