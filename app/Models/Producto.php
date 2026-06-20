<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Producto del menú: proteína, complemento, bebida o extra.
 *
 * Sin lógica de negocio (el cálculo fiscal vive en CalculadorVenta).
 * El tratamiento de ISV lo define el flag grava_isv por producto,
 * nunca la categoría hardcodeada.
 *
 * @property int $id
 * @property string $nombre
 * @property string $categoria
 * @property float $precio
 * @property bool $grava_isv
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'nombre',
        'categoria',
        'tier_combo',
        'descripcion',
        'combo_tier_carne',
        'combo_proteina_id',
        'combo_num_complementos',
        'combo_num_bebidas',
        'combo_modo',
        'precio',
        'grava_isv',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precio'                 => 'decimal:2',
            'grava_isv'              => 'boolean',
            'activo'                 => 'boolean',
            'combo_num_complementos' => 'integer',
            'combo_num_bebidas'      => 'integer',
        ];
    }

    /** Solo productos disponibles para el POS. */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeDeCategoria(Builder $query, string $categoria): Builder
    {
        return $query->where('categoria', $categoria);
    }
}
