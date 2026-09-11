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
        /* Tamaños subidos ~10% el 2026-08-15 a pedido del cliente: en la
           térmica se leía chico. A 74mm con Courier entran ~40 caracteres por
           línea (antes ~44); las líneas largas —CAI, rango autorizado,
           dirección— ya se partían, así que el ticket solo sale un poco más
           alto. Si se sube más, empiezan a partirse los totales. */
        /* TODO el documento en negrita (pedido del cliente): en térmica la
           letra normal sale tenue; el bold parejo se lee mejor. */
        html, body { margin: 0; padding: 0; font-family: 'Courier New', monospace; font-size: 11.5px; color: #000; line-height: 1.32; font-weight: 700; }
        /* Térmicas que imprimen tenue (3nStar): engrosar además el trazo.
           Complementa el ajuste de densidad del driver de la impresora. */
        body { -webkit-text-stroke: 0.25px #000; }
        .doc { width: 74mm; text-transform: uppercase; }
        .preserve { text-transform: none; }
        .orden { font-size: 16px; font-weight: bold; text-align: right; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .lg { font-size: 13.5px; }
        .sm { font-size: 10px; }
        .xs { font-size: 8.5px; }
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
        .comanda { width: 72mm; font-size: 13px; line-height: 1.35; font-family: 'Courier New', monospace; }
        .comanda .center { text-align: center; }
        .comanda .grande { font-size: 22px; font-weight: 700; }
        .comanda .medio { font-size: 15px; font-weight: 700; }
        .comanda .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .comanda table { width: 100%; border-collapse: collapse; }
        .comanda td.cant { width: 30px; font-weight: 700; vertical-align: top; font-size: 15.5px; }
        .comanda td.item { font-size: 15.5px; font-weight: 700; text-transform: uppercase; }
        .comanda .detalle { font-size: 12px; padding-left: 30px; }
        /* La nota, más grande que el nombre del plato: es lo que no se puede
           pasar por alto (mismo criterio que el ticket suelto de comanda). */
        .comanda .nota {
            font-size: 17px; font-weight: 700; text-transform: uppercase;
            padding: 1px 0 3px 30px; -webkit-text-stroke: 0.35px #000;
        }
        .comanda .banner {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 15px; padding: 4px; margin-top: 6px;
        }
    </style>
    <script>
        /* ─────────────────────────────────────────────────────────────
           LA HOJA SE AJUSTA AL ALTO REAL DEL TICKET.

           El alto estaba fijo en 250mm y el ticket CRECE con cada platillo:
           una factura detallada de 3 platos ya se partía en dos hojas y las
           leyendas del SAR salían solas en la segunda. Subir el número fijo
           no arregla nada —solo mueve el corte más adelante y alimenta papel
           de más en los tickets cortos—, así que se mide el contenido y se
           escribe el @page con ese alto.

           Corre en 'load' (con el logo y el QR ya cargados, o la medida sale
           corta) y otra vez en 'beforeprint', porque el POS imprime por
           iframe y ahí el print lo dispara la página de arriba.

           Si algo falla queda el @page fijo del CSS: el comportamiento de
           antes, nunca peor. La vista del CLIENTE se excluye — esa se ve en
           el teléfono y tiene sus propios tamaños.
           ───────────────────────────────────────────────────────────── */
        function ajustarHoja() {
            try {
                if (document.body.classList.contains('cliente')) { return; }

                var PX_POR_MM = 96 / 25.4;
                var alto = 0;

                document.querySelectorAll('.doc, .comanda').forEach(function (el) {
                    alto = Math.max(alto, el.getBoundingClientRect().height);
                });

                if (!alto) { return; }

                /* +8mm = los 6mm de margen (3 arriba + 3 abajo) y 2mm de
                   holgura, para que un redondeo no vuelva a empujar la última
                   línea a otra hoja. */
                var mm = Math.min(900, Math.max(120, Math.ceil(alto / PX_POR_MM) + 8));

                var st = document.getElementById('hoja-auto');

                if (!st) {
                    st = document.createElement('style');
                    st.id = 'hoja-auto';
                    document.head.appendChild(st);
                }

                st.textContent = '@page { size: 80mm ' + mm + 'mm; margin: 3mm; }';
            } catch (e) { /* queda el @page fijo del CSS */ }
        }

        window.addEventListener('load', ajustarHoja);
        window.addEventListener('beforeprint', ajustarHoja);
    </script>
</head>
{{-- Auto-print solo si se abre directo (el POS imprime vía iframe).
     Se ajusta la hoja ANTES de imprimir: el orden importa. --}}
<body onload="ajustarHoja(); if (window.self === window.top) window.print()">
<div class="doc">
@include('pdf.partials.factura-contenido')
</div>

<div class="salto"></div>

<div class="comanda">
@include('tickets.partials.comanda-contenido')
</div>
</body>
</html>
