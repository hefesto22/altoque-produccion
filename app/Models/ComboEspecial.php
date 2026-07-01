<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Combo promocional con nombre y precio fijo (ej: "Combo Familiar").
 *
 * No es una regla de precio (eso es App\Models\Combo): es un ítem
 * vendible. Se modela sobre la tabla productos con categoría 'combo'
 * para reusar todo el motor de cobro (CotizadorVenta::cotizarProducto),
 * el snapshot inmutable de VentaItem y el menú del día — sin tocar la
 * persistencia fiscal.
 *
 * El global scope y el creating fuerzan la categoría, así el resto del
 * sistema lo trata como cualquier producto pero esta clase solo ve combos.
 */
class ComboEspecial extends Producto
{
    protected $table = 'productos';

    protected static function booted(): void
    {
        static::addGlobalScope('combo', static fn (Builder $query): Builder => $query->where('categoria', 'combo'));

        static::creating(static function (ComboEspecial $combo): void {
            $combo->categoria = 'combo';
            $combo->tier_combo = null;
        });
    }

    /** @return BelongsTo<Producto, $this> */
    public function proteinaCombo(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'combo_proteina_id');
    }

    /** Productos que componen el platillo (modo 'platillo'). @return HasMany<ComboEspecialItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ComboEspecialItem::class, 'combo_id')->orderBy('orden');
    }

    /**
     * Misma tabla que items(), pero como relación muchos-a-muchos para que
     * el formulario use una lista de casillas (marcar/desmarcar productos),
     * más rápida que agregar uno por uno.
     *
     * @return BelongsToMany<Producto, $this>
     */
    public function productosIncluidos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'combo_especial_items', 'combo_id', 'producto_id')
            ->withPivot('cantidad')
            ->withTimestamps();
    }

    public function esPlatillo(): bool
    {
        return $this->combo_modo === 'platillo';
    }

    /** Mapea la categoría del producto al "slot" del platillo. */
    private function slotDe(string $categoria): string
    {
        return match ($categoria) {
            'proteina' => 'carne',
            'bebida'   => 'bebida',
            default    => 'complemento', // complemento y extra ocupan slot de complemento
        };
    }

    /**
     * Composición base del platillo para personalizar en el POS: cuántos
     * slots hay por categoría y los productos por defecto.
     *
     * - Modo 'platillo': conteos y defaults salen de los productos fijos.
     * - Modo 'cantidades': conteos de combo_num_* (+ 1 carne) sin defaults fijos.
     *
     * @return array{carne: int, complemento: int, bebida: int, defaults: array<int, array{producto_id: int, nombre: string, precio: float, grava_isv: bool, categoria: string}>}
     */
    public function composicionBase(): array
    {
        $counts = ['carne' => 0, 'complemento' => 0, 'bebida' => 0];
        $defaults = [];

        if ($this->esPlatillo()) {
            /** @var Collection<int, ComboEspecialItem> $items */
            $items = $this->items()->with('producto:id,nombre,precio,grava_isv,categoria')->get();

            foreach ($items as $item) {
                $p = $item->producto;

                if ($p === null) {
                    continue;
                }

                $veces = max(1, (int) $item->cantidad);
                $counts[$this->slotDe((string) $p->categoria)] += $veces;

                for ($i = 0; $i < $veces; $i++) {
                    $defaults[] = [
                        'producto_id' => (int) $p->id,
                        'nombre'      => (string) $p->nombre,
                        'precio'      => (float) $p->precio,
                        'grava_isv'   => (bool) $p->grava_isv,
                        'categoria'   => (string) $p->categoria,
                    ];
                }
            }

            return ['carne' => $counts['carne'], 'complemento' => $counts['complemento'], 'bebida' => $counts['bebida'], 'defaults' => $defaults];
        }

        // Modo cantidades: los conteos ya están definidos en el combo.
        if ($this->combo_proteina_id !== null) {
            $p = Producto::query()->find($this->combo_proteina_id);

            if ($p !== null) {
                $counts['carne'] = 1;
                $defaults[] = [
                    'producto_id' => (int) $p->id,
                    'nombre'      => (string) $p->nombre,
                    'precio'      => (float) $p->precio,
                    'grava_isv'   => (bool) $p->grava_isv,
                    'categoria'   => (string) $p->categoria,
                ];
            }
        } elseif ($this->combo_tier_carne !== null) {
            $counts['carne'] = 1;
        }

        $counts['complemento'] = (int) $this->combo_num_complementos;
        $counts['bebida'] = (int) $this->combo_num_bebidas;

        return ['carne' => $counts['carne'], 'complemento' => $counts['complemento'], 'bebida' => $counts['bebida'], 'defaults' => $defaults];
    }

    /** Etiqueta legible de la carne del combo (específica si la hay, si no el tipo). */
    public function carneLabel(?string $nombreEspecifico = null): ?string
    {
        if ($nombreEspecifico !== null && $nombreEspecifico !== '') {
            return $nombreEspecifico;
        }

        if ($this->combo_tier_carne === null || $this->combo_tier_carne === 'cualquiera') {
            return $this->combo_tier_carne === 'cualquiera' ? 'Carne a elección' : null;
        }

        return Tier::mapa()[$this->combo_tier_carne] ?? null;
    }

    /**
     * Partes del desglose de qué incluye el combo (una por componente).
     *
     * - Modo 'platillo': los productos fijos del platillo (ej:
     *   ["Pollo", "Embutido", "Huevo", "Frijoles fritos"]).
     * - Modo 'cantidades': la composición genérica (ej:
     *   ["Pollo o Cerdo", "3 complementos", "1 fresco"]).
     *
     * Recibe el nombre de la carne específica ya resuelto (para evitar N+1
     * en pantallas con varios combos); si es null, usa el tipo de carne.
     *
     * @return array<int, string>
     */
    public function detalleLineas(?string $nombreCarneEspecifica = null): array
    {
        if ($this->esPlatillo()) {
            $lineas = [];

            /** @var Collection<int, ComboEspecialItem> $items */
            $items = $this->items;

            foreach ($items as $item) {
                $nombre = $item->producto?->nombre;

                if ($nombre === null || $nombre === '') {
                    continue;
                }

                $lineas[] = $item->cantidad > 1 ? $item->cantidad.' '.$nombre : $nombre;
            }

            return $lineas;
        }

        $partes = [];

        if ($carne = $this->carneLabel($nombreCarneEspecifica)) {
            $partes[] = $carne;
        }

        if ($this->combo_num_complementos > 0) {
            $partes[] = $this->combo_num_complementos.' '.($this->combo_num_complementos === 1 ? 'complemento' : 'complementos');
        }

        if ($this->combo_num_bebidas > 0) {
            $partes[] = $this->combo_num_bebidas.' '.($this->combo_num_bebidas === 1 ? 'fresco' : 'frescos');
        }

        return $partes;
    }

    /** Desglose en una sola línea, p. ej. "Pollo + Embutido + Huevo + Frijoles fritos". */
    public function desglose(?string $nombreCarneEspecifica = null): string
    {
        return implode(' + ', $this->detalleLineas($nombreCarneEspecifica));
    }
}
