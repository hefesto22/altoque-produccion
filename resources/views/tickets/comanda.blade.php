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
            /* Subido ~8% el 2026-08-15: en la térmica se leía chico y la
               cocina lee de lejos. Los platos son líneas cortas, así que
               72mm aguanta sin partirse. */
            font-size: 13px;
            line-height: 1.35;
            /* TODO en negrita (pedido del cliente) + trazo engrosado
               para térmicas que imprimen tenue. */
            font-weight: 700;
            -webkit-text-stroke: 0.25px #000;
        }
        .center { text-align: center; }
        .grande { font-size: 22px; font-weight: 700; }
        .medio  { font-size: 15px; font-weight: 700; }
        .sep    { border-top: 1px dashed #000; margin: 6px 0; }
        table   { width: 100%; border-collapse: collapse; }
        td.cant { width: 30px; font-weight: 700; vertical-align: top; font-size: 15.5px; }
        td.item { font-size: 15.5px; font-weight: 700; text-transform: uppercase; }
        .detalle { font-size: 12px; padding-left: 30px; }
        /* La nota es la EXCEPCIÓN del plato ("sin cebolla"): si se le pasa a
           cocina, el plato se devuelve. Por eso es el texto más grande de la
           línea —más que el nombre del plato— y en caja alta. */
        .nota    {
            font-size: 17px; font-weight: 700; text-transform: uppercase;
            padding: 1px 0 3px 30px; -webkit-text-stroke: 0.35px #000;
        }
        .banner  {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 15px; padding: 4px; margin-top: 6px;
        }
    </style>
</head>
{{-- Auto-print solo si se abre directo (el POS lo imprime vía iframe; sin
     este guard saldrían dos diálogos de impresión). --}}
<body onload="if (window.self === window.top) window.print()">
@include('tickets.partials.comanda-contenido')
</body>
</html>
