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
 * @property Carbon|null $fecha_especial
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
        'es_sistema',
        'fecha_especial',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precio'                 => 'decimal:2',
            'grava_isv'              => 'boolean',
            'activo'                 => 'boolean',
            'es_sistema'             => 'boolean',
            'combo_num_complementos' => 'integer',
            'combo_num_bebidas'      => 'integer',
            'fecha_especial'         => 'date',
        ];
    }

    /**
     * El producto con el que se factura un abono a cuenta prepago.
     *
     * No es comida: es el concepto contable que lleva la única línea de la
     * factura del depósito. Vive en `productos` porque venta_items exige un
     * producto real, y se marca `es_sistema` para que el menú del POS no lo
     * muestre nunca.
     */
    public static function abonoACuenta(): self
    {
        return self::query()->firstOrCreate(
            ['es_sistema' => true],
            [
                'nombre'    => 'Abono a cuenta de consumo',
                'categoria' => 'extra',
                'precio'    => 0,
                'grava_isv' => true,
                'activo'    => true,
            ],
        );
    }

    /**
     * Fuera los conceptos internos: al POS y al catálogo solo va comida.
     *
     * @param Builder<Producto> $query
     */
    public function scopeVendibles(Builder $query): Builder
    {
        return $query->where('es_sistema', false);
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

    /**
     * Excluye los platos del día de OTRAS fechas.
     *
     * Un producto con `fecha_especial` solo existe en el menú de ese día; el
     * catálogo permanente (NULL) siempre está disponible. Va en TODA consulta
     * de menú — sobre todo en la tolerancia "si la fecha no tiene menú
     * cargado, mostrar el catálogo completo", que si no filtrara dejaría
     * salir el especial de ayer.
     */
    public function scopeDisponibleEn(Builder $query, Carbon|string $fecha): Builder
    {
        return $query->where(static function (Builder $q) use ($fecha): void {
            $q->whereNull('fecha_especial')->orWhereDate('fecha_especial', $fecha);
        });
    }

    /** Solo los platos especiales de esa fecha. */
    public function scopeDelDia(Builder $query, Carbon|string $fecha): Builder
    {
        return $query->whereNotNull('fecha_especial')->whereDate('fecha_especial', $fecha);
    }

    /** Solo catálogo permanente: deja fuera cualquier plato del día. */
    public function scopeDelCatalogo(Builder $query): Builder
    {
        return $query->whereNull('fecha_especial');
    }

    /** ¿Es un plato especial atado a una sola fecha? */
    public function esDelDia(): bool
    {
        return $this->fecha_especial !== null;
    }
}
