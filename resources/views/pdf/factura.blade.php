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
                width: min(74mm, 94vw);
                font-size: 13px;
                line-height: 1.45;
                background: #fff;
                padding: 14px 12px;
                border-radius: 10px;
                box-shadow: 0 2px 14px rgba(0,0,0,.12);
            }
            body.cliente .lg { font-size: 15px; }
            body.cliente .sm { font-size: 11.5px; }
            body.cliente .xs { font-size: 10.5px; }
            /* Barra fija abajo: el botón queda siempre a mano sin hacer scroll. */
            .acciones {
                position: fixed; left: 0; right: 0; bottom: 0; padding: 10px 14px;
                background: rgba(255,255,255,.97); border-top: 1px solid #e5e7eb;
                display: flex; justify-content: center;
                font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            }
            .acciones a {
                display: block; width: min(74mm, 94vw); text-align: center;
                padding: 13px 16px; border-radius: 10px; background: #111827;
                color: #fff; text-decoration: none; font-weight: 700; font-size: 15px;
            }
            .acciones .nota { font-size: 11px; }
        }
        /* Al imprimir, la barra no existe. */
        @media print { .acciones { display: none !important; } }
    </style>
</head>
<body @if (($paraCliente ?? false)) class="cliente" @endif>
@include('pdf.partials.factura-contenido')

@if (($paraCliente ?? false) && ($urlPdf ?? null))
    {{-- El PDF se genera recién acá, cuando alguien lo pide de verdad: así la
         factura se ve al instante y el servidor no guarda un archivo por venta. --}}
    <div class="acciones">
        <a href="{{ $urlPdf }}" target="_blank" rel="noopener">Descargar PDF</a>
    </div>
@endif
</body>
</html>
