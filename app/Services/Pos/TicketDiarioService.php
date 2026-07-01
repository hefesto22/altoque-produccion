<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Facades\DB;

/**
 * Asigna el número de orden interno (ticket) por tipo, con reinicio diario:
 * LOC-1, LL-1, DOM-1… Sirve para que cocina y caja sepan el orden del día.
 *
 * NO es el correlativo fiscal SAR (ese va con CAI en la factura). Este es
 * control interno y puede tener huecos por rollback sin consecuencia legal.
 *
 * Concurrencia: el upsert `ON CONFLICT … DO UPDATE … RETURNING` es atómico
 * en Postgres — dos cajas pidiendo número a la vez nunca reciben el mismo,
 * sin necesidad de lock explícito.
 */
final class TicketDiarioService
{
    /** @var array<string, string> */
    private const PREFIJOS = [
        'local'     => 'LOC',
        'llevar'    => 'LL',
        'domicilio' => 'DOM',
    ];

    /** Devuelve el siguiente número formateado para el tipo y el día de hoy. */
    public function siguiente(string $tipoOrden): string
    {
        $prefijo = self::PREFIJOS[$tipoOrden] ?? 'LOC';

        $n = (int) DB::selectOne(
            'INSERT INTO contador_tickets (fecha, tipo, ultimo) VALUES (?, ?, 1)
             ON CONFLICT (fecha, tipo) DO UPDATE SET ultimo = contador_tickets.ultimo + 1
             RETURNING ultimo',
            [now()->toDateString(), $tipoOrden],
        )->ultimo;

        return "{$prefijo}-{$n}";
    }
}
