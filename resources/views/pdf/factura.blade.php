<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; font-family: 'Courier New', monospace; font-size: 10.5px; color: #000; line-height: 1.32; }
        .doc { width: 74mm; text-transform: uppercase; }
        .preserve { text-transform: none; }
        .orden { font-size: 15px; font-weight: bold; text-align: right; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .lg { font-size: 12.5px; }
        .sm { font-size: 9px; }
        .xs { font-size: 8px; }
        .hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        .hr2 { border: none; border-top: 2px solid #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .items td { padding: 1px 0; }
        .tot td { padding: 0; }
        .badge { display: inline-block; border: 1px solid #000; padding: 0 4px; font-weight: bold; }
        .anulada { color: #b00; border: 2px solid #b00; padding: 3px; text-align: center; font-weight: bold; margin: 5px 0; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="doc">

    {{-- ───── Número de orden interno (control diario), arriba a la derecha ───── --}}
    @if ($f->venta?->numero_orden)
        <div class="orden">{{ $f->venta->numero_orden }}</div>
    @endif

    {{-- ───── Emisor ───── --}}
    @if (! empty($logo))
        <div class="center" style="margin-bottom:4px;"><img src="{{ $logo }}" style="max-width:40mm; max-height:18mm;" alt="logo"></div>
    @endif
    <div class="center bold lg">{{ $empresa['nombre'] }}</div>
    @if ($empresa['nombre_comercial'] && $empresa['razon_social'] !== $empresa['nombre_comercial'])
        <div class="center sm">{{ $empresa['razon_social'] }}</div>
    @endif
    <div class="center sm">RTN: {{ $empresa['rtn'] }}</div>
    <div class="center sm">{{ $empresa['direccion'] }}</div>
    <div class="center sm">Tel: {{ $empresa['telefono'] }}@if($empresa['correo']) · {{ $empresa['correo'] }}@endif</div>

    <div class="center" style="margin:4px 0;"><span class="badge">ORIGINAL — CLIENTE</span></div>

    <div class="hr2"></div>
    <div class="center bold">FACTURA</div>
    <div class="center bold lg">{{ $f->numero }}</div>
    <div class="hr2"></div>

    @if ($f->anulada)
        <div class="anulada">*** F A C T U R A&nbsp;&nbsp;A N U L A D A ***<br><span class="sm">{{ $f->motivo_anulacion }}</span></div>
    @endif

    {{-- ───── Autorización SAR ───── --}}
    <table class="sm">
        <tr><td class="bold">C.A.I.:</td></tr>
        <tr><td style="word-break:break-all;">{{ $f->cai->codigo }}</td></tr>
        <tr><td>Rango autorizado:</td></tr>
        <tr><td>Del {{ $f->cai->prefijo() }}-{{ str_pad((string) $f->cai->correlativo_desde, 8, '0', STR_PAD_LEFT) }}
                al {{ $f->cai->prefijo() }}-{{ str_pad((string) $f->cai->correlativo_hasta, 8, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><td>Fecha límite de emisión: {{ $f->cai->fecha_limite_emision->format('d/m/Y') }}</td></tr>
    </table>

    <div class="hr"></div>

    {{-- ───── Datos del documento y cliente ───── --}}
    <table>
        <tr><td>Fecha emisión:</td><td class="right">{{ $f->emitida_at->format('d/m/Y h:i A') }}</td></tr>
        <tr><td>Cliente:</td><td class="right">{{ $f->nombre_cliente }}</td></tr>
        <tr><td>RTN / ID:</td><td class="right">{{ $f->rtn_cliente ?? 'C.F.' }}</td></tr>
    </table>
    <table class="sm">
        <tr><td>No. orden compra exenta:</td><td class="right">N/A</td></tr>
        <tr><td>No. constancia exonerado:</td><td class="right">N/A</td></tr>
        <tr><td>No. registro SAG:</td><td class="right">N/A</td></tr>
    </table>

    <div class="hr"></div>

    {{-- ───── Desglose SAR: el descuento de combo se aplica ANTES del ISV.
         El detalle se imprime a precio de lista; la línea "Descuentos y
         rebajas otorgados" cuadra el subtotal con el total cobrado. ───── --}}
    @php
        $descuento = (float) $f->descuento;
        // Subtotal a precio de lista (antes de descuento). Fallback para
        // facturas previas a esta función: total + descuento.
        $subtotal = (float) $f->subtotal_lista > 0
            ? (float) $f->subtotal_lista
            : (float) $f->total + $descuento;
    @endphp

    {{-- ───── Detalle ───── --}}
    <table class="items">
        <tr class="bold sm">
            <td style="width:8%;">Ct</td>
            <td>Descripción</td>
            <td class="right" style="width:22%;">P.Unit</td>
            <td class="right" style="width:24%;">Importe</td>
        </tr>
        @if ($detallada)
            @foreach ($f->venta->items as $item)
                {{-- Se expande a precio de lista solo cuando hay referencia à la carte
                     (buffet). El platillo de precio fijo va como una sola línea. --}}
                @if (! empty($item->componentes) && (float) $item->precio_lista > 0)
                    {{-- Plato detallado: cada producto a su precio de lista (à la carte) --}}
                    @foreach ($item->componentes as $c)
                        @php
                            $cant = (int) ($c['cantidad'] ?? 1) * (int) $item->cantidad;
                            $pu = (float) ($c['precio'] ?? 0);
                        @endphp
                        <tr>
                            <td>{{ $cant }}</td>
                            <td>{{ $c['nombre'] }}</td>
                            <td class="right">{{ number_format($pu, 2) }}</td>
                            <td class="right">{{ number_format($pu * $cant, 2) }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td>{{ $item->cantidad }}</td>
                        <td>{{ $item->nombre }}</td>
                        <td class="right">{{ number_format((float) $item->precio_unitario, 2) }}</td>
                        <td class="right">{{ number_format((float) $item->importe, 2) }}</td>
                    </tr>
                    @if (! empty($item->detalle))
                        <tr><td></td><td class="sm" colspan="3" style="padding-bottom:2px;">{{ implode(', ', $item->detalle) }}</td></tr>
                    @endif
                    @if (! empty($item->nota))
                        <tr><td></td><td class="sm" colspan="3" style="padding-bottom:2px;">Nota: {{ $item->nota }}</td></tr>
                    @endif
                @endif
            @endforeach
        @else
            {{-- Concepto único, como acostumbran los restaurantes. Se muestra a
                 precio de lista para que la línea de descuento cuadre al total. --}}
            <tr>
                <td>1</td>
                <td>{{ $empresa['factura_concepto'] }}</td>
                <td class="right">{{ number_format($subtotal, 2) }}</td>
                <td class="right">{{ number_format($subtotal, 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="hr"></div>

    {{-- ───── Totales (desglose SAR completo) ───── --}}
    <table class="tot">
        <tr><td>Subtotal:</td><td class="right">L. {{ number_format($subtotal, 2) }}</td></tr>
        <tr><td>Descuentos y rebajas:</td><td class="right">L. {{ number_format($descuento, 2) }}</td></tr>
        <tr><td>Importe exento:</td><td class="right">L. {{ number_format((float) $f->exento, 2) }}</td></tr>
        <tr><td>Importe exonerado:</td><td class="right">L. 0.00</td></tr>
        <tr><td>Importe gravado 15%:</td><td class="right">L. {{ number_format((float) $f->gravado, 2) }}</td></tr>
        <tr><td>Importe gravado 18%:</td><td class="right">L. 0.00</td></tr>
        <tr><td>I.S.V. 15%:</td><td class="right">L. {{ number_format((float) $f->isv, 2) }}</td></tr>
        <tr><td>I.S.V. 18%:</td><td class="right">L. 0.00</td></tr>
        <tr class="bold lg"><td>TOTAL A PAGAR:</td><td class="right">L. {{ number_format((float) $f->total, 2) }}</td></tr>
    </table>

    <div class="hr"></div>
    <div class="sm bold">Son: {{ \App\Support\NumeroALetras::convertir((float) $f->total) }}</div>
    <div class="sm" style="margin-top:2px;">Forma de pago: EFECTIVO</div>

    <div class="hr"></div>

    {{-- ───── QR de verificación ───── --}}
    @if ($qr)
        <div class="center" style="margin:5px 0;">
            <img src="{{ $qr }}" style="width:26mm; height:26mm;" alt="QR verificación">
            <div class="sm">Escaneá para verificar esta factura</div>
            <div class="xs preserve" style="word-break:break-all;">Verificación: {{ substr($f->hash_verificacion, 0, 24) }}…</div>
        </div>
        <div class="hr"></div>
    @endif

    {{-- ───── Leyendas SAR ───── --}}
    <div class="sm center">Este documento es una representación de la factura emitida por un autoimpresor autorizado por el SAR.</div>
    <div class="sm center bold" style="margin-top:3px;">"La factura es beneficio de todos, ¡EXÍJALA!"</div>
    <div class="center bold" style="margin-top:5px;">¡Gracias por su compra!</div>

</div>
</body>
</html>
