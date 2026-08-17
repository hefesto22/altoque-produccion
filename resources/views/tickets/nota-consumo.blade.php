<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nota de consumo · Orden {{ $venta->numero_orden }}</title>
    <style>
        /* Ticket térmico 80mm. Mismo peso visual que la comanda: la térmica
           imprime tenue y esto lo lee gente de pie, con prisa. */
        @page { size: 80mm auto; margin: 2mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            width: 72mm; color: #000;
            font-size: 12.5px; line-height: 1.35; font-weight: 700;
            -webkit-text-stroke: 0.2px #000;
        }
        .center { text-align: center; }
        .der    { text-align: right; }
        .grande { font-size: 19px; }
        .medio  { font-size: 14.5px; }
        .chico  { font-size: 10.5px; font-weight: 400; }
        .sep    { border-top: 1px dashed #000; margin: 6px 0; }
        .doble  { border-top: 3px double #000; margin: 7px 0; }
        table   { width: 100%; border-collapse: collapse; }
        td.cant { width: 28px; vertical-align: top; }
        td.item { text-transform: uppercase; }
        td.imp  { text-align: right; white-space: nowrap; }
        .aviso  {
            border: 2px solid #000; padding: 5px 4px; margin: 7px 0;
            text-align: center; font-size: 11.5px; line-height: 1.4;
        }
    </style>
</head>
<body onload="window.print()">

    <div class="center grande">NOTA DE CONSUMO</div>
    <div class="center medio">{{ config('empresa.nombre', 'AL TOQUE') }}</div>

    <div class="sep"></div>

    <div class="medio">ORDEN {{ $venta->numero_orden }}</div>
    <div>{{ $venta->created_at->format('d/m/Y  h:i a') }}</div>
    @if ($cuenta !== null)
        <div class="medio">CUENTA: {{ mb_strtoupper($cuenta->nombre) }}</div>
    @endif
    @if ($venta->rtn_cliente)
        <div>RTN: {{ $venta->rtn_cliente }}</div>
    @endif
    <div class="chico">Atendio: {{ $venta->cajero?->name ?? '—' }}</div>

    <div class="sep"></div>

    <table>
        @foreach ($venta->items as $item)
            <tr>
                <td class="cant">{{ $item->cantidad }}</td>
                <td class="item">{{ $item->nombre }}</td>
                <td class="imp">{{ number_format((float) $item->precio_unitario * $item->cantidad, 2) }}</td>
            </tr>
            @if (! empty($item->detalle))
                <tr><td></td><td colspan="2" class="chico">{{ implode(', ', $item->detalle) }}</td></tr>
            @endif
        @endforeach
    </table>

    <div class="doble"></div>

    <table>
        <tr>
            <td class="grande">CONSUMIDO</td>
            <td class="der grande">L. {{ number_format((float) $venta->total, 2) }}</td>
        </tr>
    </table>

    @if ($cuenta !== null)
        <div class="sep"></div>
        <table>
            @if ($movimiento !== null)
                <tr>
                    <td>Saldo antes</td>
                    <td class="der">L. {{ number_format((float) $movimiento->saldo_despues + abs((float) $movimiento->monto), 2) }}</td>
                </tr>
                <tr>
                    <td>Este consumo</td>
                    <td class="der">- L. {{ number_format(abs((float) $movimiento->monto), 2) }}</td>
                </tr>
            @endif
            <tr>
                <td class="medio">LE QUEDA</td>
                <td class="der medio">L. {{ number_format((float) ($movimiento->saldo_despues ?? $cuenta->saldo), 2) }}</td>
            </tr>
        </table>

        @if ((float) ($movimiento->saldo_despues ?? $cuenta->saldo) < 0)
            <div class="center medio">** CUENTA EN ROJO **</div>
        @endif
    @endif

    <div class="aviso">
        ESTA NOTA NO ES DOCUMENTO FISCAL.<br>
        Este consumo ya fue cobrado y facturado<br>
        el dia del deposito a la cuenta.
    </div>

    <div class="center chico">Gracias por su visita</div>

</body>
</html>
