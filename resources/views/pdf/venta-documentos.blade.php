<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- Documento combinado: FACTURA + COMANDA en una sola impresión.
         Un solo diálogo; el salto de página separa los dos tickets y la
         térmica corta entre uno y otro. --}}
    <style>

        /* Impresión HTML directa (ticket de caja): mismo 80mm que el PDF.
           Browsershot fija el tamaño por parámetro; el print del navegador
           lo toma de @page. */
        @page { size: 80mm 250mm; margin: 3mm; }
        * { box-sizing: border-box; }
        /* TODO el documento en negrita (pedido del cliente): en térmica la
           letra normal sale tenue; el bold parejo se lee mejor. */
        html, body { margin: 0; padding: 0; font-family: 'Courier New', monospace; font-size: 10.5px; color: #000; line-height: 1.32; font-weight: 700; }
        /* Térmicas que imprimen tenue (3nStar): engrosar además el trazo.
           Complementa el ajuste de densidad del driver de la impresora. */
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
        .comanda .nota { font-size: 12px; font-weight: 700; padding-left: 28px; }
        .comanda .banner {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 14px; padding: 4px; margin-top: 6px;
        }
    </style>
</head>
{{-- Auto-print solo si se abre directo (el POS imprime vía iframe). --}}
<body onload="if (window.self === window.top) window.print()">
<div class="doc">
@include('pdf.partials.factura-contenido')
</div>

<div class="salto"></div>

<div class="comanda">
@include('tickets.partials.comanda-contenido')
</div>
</body>
</html>
