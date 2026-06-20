<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Compra gravada del restaurante (empaques, gaseosas, equipo, servicios,
 * etc.). El ISV de la compra es el crédito fiscal que se resta del ISV
 * de ventas en la declaración mensual.
 *
 * @property int $id
 * @property Carbon $fecha
 * @property string $numero_factura
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
        'fecha', 'numero_factura', 'proveedor_nombre', 'proveedor_rtn',
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
}
