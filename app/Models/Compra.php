<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Compra del restaurante (empaques, gaseosas, equipo, servicios, etc.).
 *
 * Dos tipos de documento:
 *  - factura: documento fiscal del proveedor. Su ISV es CRÉDITO FISCAL y se
 *             resta del ISV de ventas en la declaración mensual.
 *  - recibo:  compra sin factura. Se registra como gasto para tener el
 *             control, pero NO acredita ISV ni entra al Libro de Compras
 *             (sin factura con nuestro RTN el SAR no admite ese crédito).
 *
 * @property int $id
 * @property Carbon $fecha
 * @property string $tipo_documento
 * @property string|null $numero_factura
 * @property string $proveedor_nombre
 * @property string|null $proveedor_rtn
 * @property string $categoria
 * @property float $exento
 * @property float $gravado
 * @property float $isv
 * @property float $total
 */
class Compra extends Model
{
    /** @use HasFactory<CompraFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'fecha', 'tipo_documento', 'numero_factura', 'proveedor_nombre', 'proveedor_rtn',
        'categoria', 'exento', 'gravado', 'isv', 'total', 'notas', 'registrado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'   => 'date',
            'exento'  => 'decimal:2',
            'gravado' => 'decimal:2',
            'isv'     => 'decimal:2',
            'total'   => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Un recibo NO acredita ISV, venga como venga desde la UI: el importe
        // completo se guarda como exento. Blindado acá para que ningún camino
        // de entrada pueda inflar el crédito fiscal por error.
        static::saving(static function (Compra $compra): void {
            if ($compra->tipo_documento === 'recibo') {
                $compra->exento = (float) $compra->total;
                $compra->gravado = 0;
                $compra->isv = 0;
            }
        });

        // El proveedor se va guardando solo: en la próxima compra se sugiere
        // el nombre y su RTN se llena automáticamente.
        static::saved(static function (Compra $compra): void {
            Proveedor::registrar((string) $compra->proveedor_nombre, $compra->proveedor_rtn);
        });
    }

    /**
     * Solo las compras que dan crédito fiscal (facturas). Es el filtro que
     * usan la declaración mensual y el Libro de Compras del SAR.
     *
     * @param Builder<Compra> $query
     *
     * @return Builder<Compra>
     */
    public function scopeAcreditaIsv(Builder $query): Builder
    {
        return $query->where('tipo_documento', 'factura');
    }

    public function esFactura(): bool
    {
        return $this->tipo_documento === 'factura';
    }

    /** Etiqueta legible del tipo de documento. */
    public function tipoLabel(): string
    {
        return $this->esFactura() ? 'Factura' : 'Recibo';
    }
}
