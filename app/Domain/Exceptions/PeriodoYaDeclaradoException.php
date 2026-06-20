<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó declarar (o re-declarar) un período que ya está cerrado.
 * Para corregirlo hay que reabrirlo primero (rectificativa).
 */
final class PeriodoYaDeclaradoException extends DeclaracionException
{
    public function __construct(int $anio, int $mes)
    {
        parent::__construct("El período {$mes}/{$anio} ya está declarado. Reabrir para rectificar.");
    }
}
