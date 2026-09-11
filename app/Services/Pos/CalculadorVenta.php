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
 * VENTA EXONERADA (Orden de Compra Exenta del PAMEH):
 *   Con `$exonerada = true` lo que iría a 'gravado' va a 'exonerado' y el
 *   ISV queda en CERO. Las líneas tienen que llegar YA tarifadas en neto
 *   (LineaVenta::sinIsv) — acá no se vuelve a dividir entre 1+tasa, o se
 *   quitaría el impuesto dos veces.
 *
 *   Lo exento NO se toca: sigue siendo exento. Exento y exonerado son cosas
 *   distintas ante el SAR y van en casillas distintas de la declaración.
 *
 * La tasa NUNCA se hardcodea aquí: se inyecta desde
 * config('honduras.impuestos.isv.tasa_general') vía el container.
 */
final class CalculadorVenta implements CalculaImpuestos
{
    public function __construct(private readonly float $tasaIsv) {}

    /**
     * @param iterable<LineaVenta> $lineas
     * @param bool $exonerada Venta con OCE: el importe gravable se declara
     *                        exonerado y no genera ISV.
     */
    public function calcular(iterable $lineas, bool $exonerada = false): ResumenVenta
    {
        $gravadoBase = 0.0;
        $exonerado = 0.0;
        $isv = 0.0;
        $exento = 0.0;
        $subtotalLista = 0.0;
        $descuento = 0.0;

        foreach ($lineas as $linea) {
            $subtotalLista += $linea->subtotalLista();
            $descuento += $linea->descuento();

            foreach ($linea->repartoNeto() as $parte) {
                $neto = $parte['neto'];

                if (! $parte['grava']) {
                    $exento += $neto;

                    continue;
                }

                if ($exonerada) {
                    // La línea ya viene en neto: este importe ES la base.
                    $exonerado += $neto;

                    continue;
                }

                $base = round($neto / (1 + $this->tasaIsv), 2);
                $gravadoBase += $base;
                $isv += round($neto - $base, 2);
            }
        }

        return new ResumenVenta(
            gravado: round($gravadoBase, 2),
            exento: round($exento, 2),
            isv: round($isv, 2),
            total: round($gravadoBase + $isv + $exento + $exonerado, 2),
            subtotalLista: round($subtotalLista, 2),
            descuento: round($descuento, 2),
            exonerado: round($exonerado, 2),
        );
    }

    /**
     * @param iterable<LineaVenta> $lineas
     *
     * @return array<int, LineaVenta>
     */
    public function tarifarSinIsv(iterable $lineas): array
    {
        $netas = [];

        foreach ($lineas as $linea) {
            $netas[] = $linea->sinIsv($this->tasaIsv);
        }

        return $netas;
    }
}
