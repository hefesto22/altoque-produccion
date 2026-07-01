<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\ResumenVenta;

/**
 * Calculador de venta — puro, centralizado, sin efectos secundarios.
 *
 * Modelo fiscal CONFIRMADO con Mauricio: "ISV incluido en el precio".
 * El precio de cara al cliente ya contiene el impuesto; al cobrar se
 * DESGLOSA, no se suma nada. Por eso el total nunca cambia entre
 * recibo y factura: lo único que cambia es si se imprime documento
 * fiscal con correlativo SAR.
 *
 *   Para una porción que grava:
 *     base = neto / (1 + tasa)
 *     isv  = neto - base
 *   Para una porción exenta:
 *     todo el neto va a 'exento', isv = 0
 *
 * DESCUENTO DE COMBO (confirmado con Mauricio + criterio SAR):
 *   El ISV se calcula SIEMPRE sobre el neto realmente cobrado (precio de
 *   combo), nunca sobre el precio de lista. El descuento se acumula aparte
 *   para mostrarlo desglosado en la factura ("Descuentos y rebajas
 *   otorgados"), pero no altera el impuesto.
 *
 *   Cuando un plato mezcla productos gravados y exentos, el descuento se
 *   prorratea por peso de lista (lo resuelve LineaVenta::repartoNeto), de
 *   modo que la base gravada y la exenta quedan correctas al centavo.
 *
 * La tasa NUNCA se hardcodea aquí: se inyecta desde
 * config('honduras.impuestos.isv.tasa_general') vía el container.
 */
final class CalculadorVenta implements CalculaImpuestos
{
    public function __construct(private readonly float $tasaIsv) {}

    /**
     * @param iterable<LineaVenta> $lineas
     */
    public function calcular(iterable $lineas): ResumenVenta
    {
        $gravadoBase = 0.0;
        $isv = 0.0;
        $exento = 0.0;
        $subtotalLista = 0.0;
        $descuento = 0.0;

        foreach ($lineas as $linea) {
            $subtotalLista += $linea->subtotalLista();
            $descuento += $linea->descuento();

            foreach ($linea->repartoNeto() as $parte) {
                $neto = $parte['neto'];

                if ($parte['grava']) {
                    $base = round($neto / (1 + $this->tasaIsv), 2);
                    $gravadoBase += $base;
                    $isv += round($neto - $base, 2);
                } else {
                    $exento += $neto;
                }
            }
        }

        return new ResumenVenta(
            gravado: round($gravadoBase, 2),
            exento: round($exento, 2),
            isv: round($isv, 2),
            total: round($gravadoBase + $isv + $exento, 2),
            subtotalLista: round($subtotalLista, 2),
            descuento: round($descuento, 2),
        );
    }
}
