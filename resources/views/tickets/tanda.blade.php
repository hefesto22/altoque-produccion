<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tanda de impresión ({{ count($bloques) }})</title>
    {{-- Varios tickets en UN solo documento, separados por salto de página:
         un único diálogo de impresión y la térmica corta en cada salto.
         Los estilos son los mismos de pdf/venta-documentos.blade.php, que ya
         imprime factura + comanda juntas todos los días. --}}
    <style>
        @page { size: 80mm 250mm; margin: 3mm; }
        * { box-sizing: border-box; }
        /* TODO el documento en negrita (pedido del cliente): en térmica la
           letra normal sale tenue; el bold parejo se lee mejor. */
        html, body { margin: 0; padding: 0; font-family: 'Courier New', monospace; font-size: 10.5px; color: #000; line-height: 1.32; font-weight: 700; }
        /* Térmicas que imprimen tenue (3nStar): engrosar además el trazo. */
        body { -webkit-text-stroke: 0.25px #000; }
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
        /* ── Comanda (scopeada para no pisar los estilos de la factura) ── */
        .salto { page-break-after: always; }
        .comanda { width: 72mm; font-size: 12px; line-height: 1.35; font-family: 'Courier New', monospace; }
        .comanda .center { text-align: center; }
        .comanda .grande { font-size: 20px; font-weight: 700; }
        .comanda .medio { font-size: 14px; font-weight: 700; }
        .comanda .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .comanda table { width: 100%; border-collapse: collapse; }
        .comanda td.cant { width: 28px; font-weight: 700; vertical-align: top; font-size: 14px; }
        .comanda td.item { font-size: 14px; font-weight: 700; text-transform: uppercase; }
        .comanda .detalle { font-size: 11px; padding-left: 28px; }
        /* La nota, más grande que el nombre del plato: es lo que no se puede
           pasar por alto (mismo criterio que el ticket suelto de comanda). */
        .comanda .nota {
            font-size: 15.5px; font-weight: 700; text-transform: uppercase;
            padding: 1px 0 3px 28px; -webkit-text-stroke: 0.3px #000;
        }
        .comanda .banner {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 14px; padding: 4px; margin-top: 6px;
        }
    </style>
</head>
{{-- Auto-print solo si se abre directo (el POS imprime vía iframe). --}}
<body onload="if (window.self === window.top) window.print()">
@foreach ($bloques as $i => $bloque)
    @if ($i > 0)
        <div class="salto"></div>
    @endif

    @if ($bloque['tipo'] === 'comanda')
        <div class="comanda">
            @include('tickets.partials.comanda-contenido', $bloque['datos'])
        </div>
    @else
        @include('pdf.partials.factura-contenido', $bloque['datos'])
    @endif
@endforeach
</body>
</html>
