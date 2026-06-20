<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó declarar un mes que todavía no termina. El mes en curso se
 * puede previsualizar, pero no declarar hasta que finalice.
 */
final class PeriodoNoFinalizadoException extends DeclaracionException
{
    public function __construct(int $anio, int $mes)
    {
        parent::__construct("El período {$mes}/{$anio} aún no finaliza. Solo se puede declarar un mes ya terminado.");
    }
}
