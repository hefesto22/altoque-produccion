<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\ResumenVenta;
use App\Models\Combo;
use App\Models\ComboEspecial;
use App\Models\Producto;

/**
 * Motor de precios del POS. Traduce lo que el cajero selecciona en
 * líneas de venta con el precio correcto, aplicando la regla de combo
 * confirmada con Mauricio:
 *
 *   - Plato = proteína + N complementos.
 *   - Si existe un combo (tier de la proteína, N) → ese precio.
 *   - Si no calza → suma individual (proteína + Σ complementos).
 *   - Bebidas y extras: línea propia con su precio (la bebida grava ISV).
 *
 * El plato se modela como UNA línea exenta con los complementos en
 * `detalle` (para el ticket). Así el total y el ISV cuadran exactos y
 * el snapshot queda inmutable.
 */
final class CotizadorVenta
{
    public function __construct(private readonly CalculaImpuestos $calculador) {}

    /**
     * Cotiza un plato (proteína + complementos) como una sola línea.
     *
     * @param array<int, int> $complementoIds ids de complementos (puede repetir)
     */
    public function cotizarPlato(int $proteinaId, array $complementoIds): LineaVenta
    {
        $proteina = Producto::query()->activos()->findOrFail($proteinaId);

        $mapa = Producto::query()->activos()
            ->whereIn('id', array_values(array_unique($complementoIds)))
            ->get()
            ->keyBy('id');

        $preciosComplementos = [];
        $detalle = [];

        foreach ($complementoIds as $id) {
            $complemento = $mapa->get($id);

            if ($complemento === null) {
                continue;
            }

            $preciosComplementos[] = (float) $complemento->precio;
            $detalle[] = $complemento->nombre;
        }

        $n = count($detalle);
        $sumaIndividual = (float) $proteina->precio + array_sum($preciosComplementos);

        // 1) ¿Hay un combo exacto para esa cantidad de complementos?
        $combo = Combo::query()->activo()
            ->where('tier', $proteina->tier_combo)
            ->where('complementos', $n)
            ->first();

        if ($combo !== null) {
            $precio = (float) $combo->precio;
        } else {
            // 2) Si pasa del combo más grande, se cobra ese combo + cada
            //    complemento extra a su precio individual (los del combo van
            //    incluidos; del siguiente en adelante se suma el precio).
            $comboMax = Combo::query()->activo()
                ->where('tier', $proteina->tier_combo)
                ->orderByDesc('complementos')
                ->first();

            if ($comboMax !== null && $n > $comboMax->complementos) {
                $extra = array_sum(array_slice($preciosComplementos, $comboMax->complementos));
                $precio = (float) $comboMax->precio + $extra;
            } else {
                // 3) Menos complementos que cualquier combo: suma individual.
                $precio = $sumaIndividual;
            }
        }

        $nombre = $n > 0
            ? "{$proteina->nombre} + {$n} complemento".($n > 1 ? 's' : '')
            : $proteina->nombre;

        // El tratamiento de ISV del plato sigue el flag de la proteína
        // (comida gravada o exenta, según configuración del producto).
        return new LineaVenta(
            productoId: $proteina->id,
            nombre: $nombre,
            precioUnitario: round($precio, 2),
            cantidad: 1,
            gravaIsv: (bool) $proteina->grava_isv,
            detalle: $detalle,
        );
    }

    /**
     * Cotiza un producto suelto (bebida o extra) como su propia línea,
     * respetando su flag grava_isv (la bebida grava; el extra según su flag).
     */
    public function cotizarProducto(int $productoId, int $cantidad = 1): LineaVenta
    {
        $producto = Producto::query()->activos()->findOrFail($productoId);

        // Un combo especial lleva su desglose (carne + complementos + frescos)
        // como detalle, para el ticket y la comanda de cocina.
        $detalle = [];

        if ($producto->categoria === 'combo') {
            $combo = ComboEspecial::query()->withoutGlobalScopes()
                ->with('items.producto:id,nombre')
                ->find($producto->id);

            if ($combo !== null) {
                $nombreCarne = $combo->combo_proteina_id !== null
                    ? Producto::query()->whereKey($combo->combo_proteina_id)->value('nombre')
                    : null;

                // Modo platillo → cada producto como línea de detalle (la cocina
                // los recibe uno a uno). Modo cantidades → el desglose genérico.
                $detalle = $combo->detalleLineas($nombreCarne);
            }
        }

        return new LineaVenta(
            productoId: $producto->id,
            nombre: $producto->nombre,
            precioUnitario: (float) $producto->precio,
            cantidad: $cantidad,
            gravaIsv: (bool) $producto->grava_isv,
            detalle: $detalle,
        );
    }

    /**
     * Desglose fiscal en vivo de un conjunto de líneas (para el total
     * corriendo en la pantalla de cobro).
     *
     * @param array<int, LineaVenta> $lineas
     */
    public function resumir(array $lineas): ResumenVenta
    {
        return $this->calculador->calcular($lineas);
    }
}
