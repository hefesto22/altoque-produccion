<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\ValueObjects\ComponenteLinea;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\ResumenVenta;
use App\Models\Combo;
use App\Models\ComboEspecial;
use App\Models\ComboEspecialItem;
use App\Models\Producto;
use Illuminate\Support\Collection;

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

        // La proteína es el primer componente; los complementos siguen.
        // Cada uno conserva su precio de lista y su flag grava_isv para el
        // desglose del descuento y la factura detallada.
        $componentes = [
            new ComponenteLinea(
                nombre: $proteina->nombre,
                precio: (float) $proteina->precio,
                gravaIsv: (bool) $proteina->grava_isv,
            ),
        ];

        foreach ($complementoIds as $id) {
            $complemento = $mapa->get($id);

            if ($complemento === null) {
                continue;
            }

            $preciosComplementos[] = (float) $complemento->precio;
            $detalle[] = $complemento->nombre;
            $componentes[] = new ComponenteLinea(
                nombre: $complemento->nombre,
                precio: (float) $complemento->precio,
                gravaIsv: (bool) $complemento->grava_isv,
            );
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

        // gravaIsv de la línea = flag de la proteína (resumen rápido para el
        // POS); el desglose fiscal real lo hace `componentes`, donde cada
        // producto grava según su propio flag y el descuento se prorratea.
        // precioListaUnitario = suma à la carte: si hubo combo, el descuento
        // sale de (lista − precio cobrado); si no calzó combo, lista == precio.
        return new LineaVenta(
            productoId: $proteina->id,
            nombre: $nombre,
            precioUnitario: round($precio, 2),
            cantidad: 1,
            gravaIsv: (bool) $proteina->grava_isv,
            detalle: $detalle,
            precioListaUnitario: round($sumaIndividual, 2),
            componentes: $componentes,
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
        $componentes = [];

        if ($producto->categoria === 'combo') {
            $combo = ComboEspecial::query()->withoutGlobalScopes()
                ->with('items.producto:id,nombre,precio,grava_isv')
                ->find($producto->id);

            if ($combo !== null) {
                $nombreCarne = $combo->combo_proteina_id !== null
                    ? Producto::query()->whereKey($combo->combo_proteina_id)->value('nombre')
                    : null;

                // Modo platillo → cada producto como línea de detalle (la cocina
                // los recibe uno a uno). Modo cantidades → el desglose genérico.
                $detalle = $combo->detalleLineas($nombreCarne);

                // Platillo armado: cada producto fijo aporta al desglose fiscal
                // según SU propio flag grava_isv. Si el platillo grava pero
                // incluye un exento (o al revés), el ISV se prorratea correcto
                // sobre el precio fijo — no es todo-o-nada por el flag del combo.
                if ($combo->esPlatillo()) {
                    /** @var Collection<int, ComboEspecialItem> $items */
                    $items = $combo->items;

                    foreach ($items as $item) {
                        $p = $item->producto;

                        if ($p === null) {
                            continue;
                        }

                        $componentes[] = new ComponenteLinea(
                            nombre: $p->nombre,
                            precio: (float) $p->precio,
                            gravaIsv: (bool) $p->grava_isv,
                            cantidad: (int) $item->cantidad,
                        );
                    }
                }
            }
        }

        // Precio de lista del platillo: la suma à la carte de sus componentes.
        // Solo se informa si supera el precio fijo — así la factura detallada
        // muestra cada producto a su precio y el ahorro en "Descuentos y
        // rebajas". Si la suma no supera el fijo, no hay descuento (nunca
        // se inventa uno negativo).
        $listaPlatillo = round(array_sum(array_map(
            static fn (ComponenteLinea $c): float => $c->precio * $c->cantidad,
            $componentes,
        )), 2);

        return new LineaVenta(
            productoId: $producto->id,
            nombre: $producto->nombre,
            precioUnitario: (float) $producto->precio,
            cantidad: $cantidad,
            gravaIsv: (bool) $producto->grava_isv,
            precioListaUnitario: $listaPlatillo > (float) $producto->precio ? $listaPlatillo : null,
            detalle: $detalle,
            componentes: $componentes,
        );
    }

    /**
     * Cotiza un platillo completo PERSONALIZADO. La selección son los
     * productos que el cliente eligió para llenar los slots del platillo.
     *
     * Reglas (confirmadas con Mauricio):
     *  - Los primeros N por categoría (N = conteo base) van a precio fijo
     *    (swaps libres: no importa cuáles ni si son más caros).
     *  - Lo que pase del conteo base es EXTRA y se cobra a su precio.
     *  - Quitar no baja el precio (el fijo se mantiene).
     *
     * Devuelve la línea base (precio fijo, ISV prorrateado sobre sus slots)
     * más una línea por cada extra (a su precio y su propio flag de ISV).
     *
     * @param array<int, array{producto_id: int, nombre: string, precio: float, grava_isv: bool, categoria: string}> $seleccion
     *
     * @return array<int, LineaVenta>
     */
    public function cotizarPlatilloPersonalizado(int $comboId, array $seleccion, string $nota = ''): array
    {
        $combo = ComboEspecial::query()->withoutGlobalScopes()->find($comboId);

        if ($combo === null) {
            return [$this->cotizarProducto($comboId)];
        }

        $base = $combo->composicionBase();

        // Agrupa la selección por slot conservando el orden (los primeros son base).
        $porSlot = ['carne' => [], 'complemento' => [], 'bebida' => []];

        foreach ($seleccion as $s) {
            $slot = match ($s['categoria']) {
                'proteina' => 'carne',
                'bebida'   => 'bebida',
                default    => 'complemento',
            };
            $porSlot[$slot][] = $s;
        }

        $baseComponentes = [];
        $baseDetalle = [];
        $extras = [];

        foreach ($porSlot as $slot => $items) {
            $baseCount = (int) ($base[$slot] ?? 0);

            foreach ($items as $i => $s) {
                if ($i < $baseCount) {
                    $baseComponentes[] = new ComponenteLinea($s['nombre'], (float) $s['precio'], (bool) $s['grava_isv']);
                    $baseDetalle[] = $s['nombre'];
                } else {
                    $extras[] = $s; // más allá del conteo base → extra
                }
            }
        }

        // Suma à la carte de los slots base: si supera el precio fijo, la
        // factura detallada muestra cada componente a su precio y el ahorro
        // como "Descuentos y rebajas".
        $listaBase = round(array_sum(array_map(
            static fn (ComponenteLinea $c): float => $c->precio * $c->cantidad,
            $baseComponentes,
        )), 2);

        // Línea base a precio fijo (el ISV se prorratea sobre los slots base).
        $lineas = [new LineaVenta(
            productoId: $combo->id,
            nombre: $combo->nombre,
            precioUnitario: round((float) $combo->precio, 2),
            cantidad: 1,
            gravaIsv: (bool) $combo->grava_isv,
            precioListaUnitario: $listaBase > (float) $combo->precio ? $listaBase : null,
            detalle: $baseDetalle,
            componentes: $baseComponentes,
            nota: $nota,
        )];

        // Cada extra es su propia línea a su precio, con su propio flag.
        foreach ($extras as $s) {
            $lineas[] = new LineaVenta(
                productoId: (int) $s['producto_id'],
                nombre: $s['nombre'].' (extra)',
                precioUnitario: round((float) $s['precio'], 2),
                cantidad: 1,
                gravaIsv: (bool) $s['grava_isv'],
            );
        }

        return $lineas;
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
