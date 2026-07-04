{{-- Contenido del ticket de comanda. Compartido por la vista standalone
     y por el documento combinado factura+comanda. --}}
@php($nombreCliente = trim((string) $comanda->cliente_nombre))
    @if ($nombreCliente !== '')
        {{-- Número a la izquierda y NOMBRE en la esquina superior derecha:
             la cocina identifica de quién es el pedido de un vistazo. --}}
        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:4px;">
            <div class="grande">{{ $comanda->venta?->numero_orden ?? $comanda->numero }}</div>
            <div style="font-size:15px; font-weight:700; text-transform:uppercase; text-align:right; max-width:42mm; word-break:break-word;">{{ mb_strtoupper($nombreCliente) }}</div>
        </div>
    @else
        <div class="center grande">{{ $comanda->venta?->numero_orden ?? $comanda->numero }}</div>
    @endif
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

    @if ($comanda->cliente_telefono || $comanda->cliente_direccion)
        <div class="sep"></div>
        @if ($comanda->cliente_telefono)<div>TEL: {{ $comanda->cliente_telefono }}</div>@endif
        @if ($comanda->cliente_direccion)<div>DIR: {{ mb_strtoupper($comanda->cliente_direccion) }}</div>@endif
    @endif

    @if ($comanda->venta && ! $comanda->venta->pagada)
        <div class="banner">PENDIENTE DE PAGO · TOTAL L. {{ number_format((float) $comanda->venta->total, 2) }}</div>
    @endif
