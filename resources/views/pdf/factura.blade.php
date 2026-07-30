<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    @if (($paraCliente ?? false))
        {{-- Sin esto el teléfono renderiza a 980px y encoge la factura a un
             sello. Solo va en la vista del cliente: en el PDF y en la térmica
             el ancho lo manda @page (80mm), no el viewport. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Factura {{ $f->numero }}</title>
    @endif
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
        /* SOLO pantalla — visores del admin (historial de cliente, detalle de
           corte): ticket centrado con un respiro arriba. NO afecta la
           impresión de caja ni el PDF de WhatsApp: ambos renderizan con
           media print, que ignora esta regla. */
        @media screen {
            body { background: #fff; padding: 8px 0 16px; }
            .doc { margin: 0 auto; }
        }

        /* Vista del CLIENTE (link de WhatsApp, se abre en el teléfono).
           El ticket está pensado para 74mm de térmica: en un celular se ve
           diminuto. Acá se agranda y se centra. Todo va en @media screen y
           bajo .cliente, así la impresión de caja y el PDF no se enteran. */
        @media screen {
            body.cliente { background: #f3f4f6; padding: 12px 0 96px; }
            body.cliente .doc {
                /* En teléfono ocupa casi todo el ancho; en escritorio no pasa
                   de 420px para que no se estire y quede ilegible. */
                width: min(420px, 94vw);
                font-size: 15px;
                line-height: 1.5;
                background: #fff;
                padding: 18px 16px;
                border-radius: 12px;
                box-shadow: 0 2px 14px rgba(0,0,0,.12);
            }
            body.cliente .lg { font-size: 18px; }
            body.cliente .sm { font-size: 13px; }
            body.cliente .xs { font-size: 12px; }
            /* El QR se dibuja a 150px fijos: que acompañe el ancho. */
            body.cliente img { max-width: 100%; height: auto; }
            /* Tabla de ítems: que los nombres largos partan en vez de desbordar. */
            body.cliente td { word-break: break-word; }
            /* Barra fija abajo: el botón queda siempre a mano sin hacer scroll. */
            .acciones {
                position: fixed; left: 0; right: 0; bottom: 0; padding: 10px 14px;
                background: rgba(255,255,255,.97); border-top: 1px solid #e5e7eb;
                display: flex; flex-direction: column; align-items: center; gap: 5px;
                font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            }
            .acciones button {
                display: block; width: min(420px, 94vw); text-align: center;
                padding: 13px 16px; border: none; border-radius: 10px; background: #111827;
                color: #fff; font-weight: 700; font-size: 15px; cursor: pointer;
                font-family: inherit;
            }
            .acciones .nota { font-size: 11.5px; color: #6b7280; }
        }
        /* Al imprimir, la barra no existe. */
        @media print { .acciones { display: none !important; } }
    </style>

    @if (($paraCliente ?? false))
        {{-- Impresión desde el TELÉFONO ("Guardar como PDF").
             El @page de arriba fija 80x250mm para la térmica de caja; el
             teléfono imprime en A4 y con esa regla el ticket queda pegado
             arriba a la izquierda de la hoja. Acá se pisa SOLO para la vista
             del cliente: hoja normal y ticket centrado. Va después del <style>
             de arriba a propósito — gana el último. --}}
        <style>
            @page { size: auto; margin: 14mm; }

            @media print {
                html, body { background: #fff; }
                body.cliente { padding: 0; }
                body.cliente .doc {
                    width: 80mm;
                    margin: 0 auto;              /* centrado en la hoja */
                    font-size: 11px;
                    line-height: 1.35;
                    background: transparent;
                    box-shadow: none;
                    border-radius: 0;
                    padding: 0;
                }
                body.cliente .lg { font-size: 13px; }
                body.cliente .sm { font-size: 9.5px; }
                body.cliente .xs { font-size: 8.5px; }
            }
        </style>
    @endif
</head>
<body @if (($paraCliente ?? false)) class="cliente" @endif>
@include('pdf.partials.factura-contenido')

@if (($paraCliente ?? false))
    {{-- Guardar la factura NO pasa por el servidor: se abre el diálogo de
         impresión del teléfono y ahí el propio sistema ofrece "Guardar como
         PDF". Antes esto llamaba a una ruta que levantaba Chromium para armar
         el archivo; era lento y, si se cacheaba, dejaba un PDF por venta en el
         disco. El teléfono ya sabe hacerlo: que lo haga él. --}}
    <div class="acciones">
        <button type="button" onclick="window.print()">Descargar PDF</button>
        <div class="nota">En el diálogo elegí “Guardar como PDF”</div>
    </div>
@endif
</body>
</html>
