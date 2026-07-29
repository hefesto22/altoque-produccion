<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorteCajas\Pages;

use App\Filament\Resources\CorteCajas\CorteCajaResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de cortes. Sin acciones de cabecera a propósito: el turno se abre
 * únicamente desde el POS (Punto de Venta), a nombre de quien está logueado
 * y con el permiso `AbrirTurno`. Tener dos puntos de apertura confundía al
 * personal de caja, así que este quedó eliminado.
 */
class ListCorteCajas extends ListRecords
{
    protected static string $resource = CorteCajaResource::class;
}
