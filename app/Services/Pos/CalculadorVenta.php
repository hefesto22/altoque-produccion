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
 *   Para una línea que grava:
 *     base = importe / (1 + tasa)
 *     isv  = importe - base
 *   Para una línea exenta:
 *     todo el importe va a 'exento', isv = 0
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

        foreach ($lineas as $linea) {
            $importe = $linea->importe();

            if ($linea->gravaIsv) {
                $base = round($importe / (1 + $this->tasaIsv), 2);
                $gravadoBase += $base;
                $isv += round($importe - $base, 2);
            } else {
                $exento += $importe;
            }
        }

        return new ResumenVenta(
            gravado: round($gravadoBase, 2),
            exento: round($exento, 2),
            isv: round($isv, 2),
            total: round($gravadoBase + $isv + $exento, 2),
        );
    }
}
