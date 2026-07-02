<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comanda {{ $comanda->numero }}</title>
    <style>
        /* Ticket térmico 80mm: tipografía mono grande, sin color, alto contraste. */
        @page { size: 80mm auto; margin: 2mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            width: 72mm;
            color: #000;
            font-size: 12px;
            line-height: 1.35;
        }
        .center { text-align: center; }
        .grande { font-size: 20px; font-weight: 700; }
        .medio  { font-size: 14px; font-weight: 700; }
        .sep    { border-top: 1px dashed #000; margin: 6px 0; }
        table   { width: 100%; border-collapse: collapse; }
        td.cant { width: 28px; font-weight: 700; vertical-align: top; font-size: 14px; }
        td.item { font-size: 14px; font-weight: 700; text-transform: uppercase; }
        .detalle { font-size: 11px; padding-left: 28px; }
        .nota    { font-size: 12px; font-weight: 700; padding-left: 28px; }
        .banner  {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 14px; padding: 4px; margin-top: 6px;
        }
    </style>
</head>
{{-- Auto-print solo si se abre directo (el POS lo imprime vía iframe; sin
     este guard saldrían dos diálogos de impresión). --}}
<body onload="if (window.self === window.top) window.print()">
    <div class="center grande">{{ $comanda->venta?->numero_orden ?? $comanda->numero }}</div>
    <div class="center medio">{{ mb_strtoupper($comanda->tipoLabel()) }}</div>
    <div class="center">Comanda {{ $comanda->numero }} · {{ $comanda->created_at->format('d/m/Y h:i A') }}</div>

    <div class="sep"></div>

    <table>
        @foreach (($comanda->items ?? []) as $item)
            <tr>
                <td class="cant">{{ $item['cantidad'] ?? 1 }}</td>
                <td class="item">{{ $item['nombre'] ?? '' }}</td>
            </tr>
            @foreach (($item['detalle'] ?? []) as $d)
                <tr><td colspan="2" class="detalle">- {{ is_array($d) ? ($d['nombre'] ?? '') : $d }}</td></tr>
            @endforeach
            @if (! empty($item['nota']))
                <tr><td colspan="2" class="nota">&raquo; {{ $item['nota'] }}</td></tr>
            @endif
        @endforeach
    </table>

    @if ($comanda->cliente_nombre || $comanda->cliente_telefono || $comanda->cliente_direccion)
        <div class="sep"></div>
        @if ($comanda->cliente_nombre)<div>CLIENTE: {{ mb_strtoupper($comanda->cliente_nombre) }}</div>@endif
        @if ($comanda->cliente_telefono)<div>TEL: {{ $comanda->cliente_telefono }}</div>@endif
        @if ($comanda->cliente_direccion)<div>DIR: {{ mb_strtoupper($comanda->cliente_direccion) }}</div>@endif
    @endif

    @if ($comanda->venta && ! $comanda->venta->pagada)
        <div class="banner">PENDIENTE DE PAGO · TOTAL L. {{ number_format((float) $comanda->venta->total, 2) }}</div>
    @endif
</body>
</html>
