<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte un monto a su representación en letras para la factura SAR
 * (requisito: "Cantidad en letras"). Ej: 235.00 → "DOSCIENTOS TREINTA Y
 * CINCO LEMPIRAS CON 00/100".
 */
final class NumeroALetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];

    private const ESPECIALES = [
        10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE',
        16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE',
        20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO',
        25 => 'VEINTICINCO', 26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE',
    ];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function convertir(float $monto, string $moneda = 'LEMPIRAS'): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $letras = $entero === 0 ? 'CERO' : self::enteroALetras($entero);

        return trim($letras).' '.$moneda.' CON '.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT).'/100';
    }

    private static function enteroALetras(int $n): string
    {
        if ($n < 0) {
            return 'MENOS '.self::enteroALetras(abs($n));
        }

        if ($n < 1000000) {
            return self::miles($n);
        }

        $millones = intdiv($n, 1000000);
        $resto = $n % 1000000;
        $texto = $millones === 1 ? 'UN MILLÓN' : self::miles($millones).' MILLONES';

        return $resto === 0 ? $texto : $texto.' '.self::miles($resto);
    }

    private static function miles(int $n): string
    {
        if ($n < 1000) {
            return self::centenas($n);
        }

        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $texto = $miles === 1 ? 'MIL' : self::centenas($miles).' MIL';

        return $resto === 0 ? $texto : $texto.' '.self::centenas($resto);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }

        $c = intdiv($n, 100);
        $resto = $n % 100;
        $texto = self::CENTENAS[$c];

        if ($resto === 0) {
            return $texto;
        }

        return trim($texto.' '.self::decenas($resto));
    }

    private static function decenas(int $n): string
    {
        if ($n < 10) {
            return self::UNIDADES[$n];
        }

        if (isset(self::ESPECIALES[$n])) {
            return self::ESPECIALES[$n];
        }

        $d = intdiv($n, 10);
        $u = $n % 10;

        return $u === 0 ? self::DECENAS[$d] : self::DECENAS[$d].' Y '.self::UNIDADES[$u];
    }
}
