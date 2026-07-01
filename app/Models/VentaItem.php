<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una venta. Snapshot inmutable de lo cobrado: nombre,
 * precio_unitario y grava_isv quedan congelados al momento de la venta.
 *
 * @property int $id
 * @property int $venta_id
 * @property int $producto_id
 * @property string $nombre
 * @property float $precio_unitario
 * @property float|null $precio_lista
 * @property int $cantidad
 * @property bool $grava_isv
 * @property array<int, string>|null $detalle
 * @property string|null $nota
 * @property array<int, array{nombre: string, precio: float, grava_isv: bool, cantidad: int}>|null $componentes
 * @property float $importe
 * @property float $descuento
 */
class VentaItem extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'venta_id',
        'producto_id',
        'nombre',
        'precio_unitario',
        'precio_lista',
        'cantidad',
        'grava_isv',
        'detalle',
        'nota',
        'componentes',
        'importe',
        'descuento',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'precio_lista'    => 'decimal:2',
            'cantidad'        => 'integer',
            'grava_isv'       => 'boolean',
            'detalle'         => 'array',
            'componentes'     => 'array',
            'importe'         => 'decimal:2',
            'descuento'       => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
