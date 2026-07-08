<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
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
    </style>
</head>
<body>
@include('pdf.partials.factura-contenido')
</body>
</html>
