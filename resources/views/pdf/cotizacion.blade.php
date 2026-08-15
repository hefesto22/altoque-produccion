<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
@if (($paraCliente ?? false))
    {{-- Sin esto el teléfono renderiza a 980px y la cotización se ve como un
         sello. Solo va en la vista del cliente: en el PDF el ancho lo manda
         Browsershot (carta), no el viewport. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
@endif
<title>Cotización {{ $c->numero }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Helvetica Neue', Arial, sans-serif;
        font-size: 12px; color: #1a1a1a; line-height: 1.45;
    }
    .encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
    .logo { max-height: 84px; max-width: 180px; object-fit: contain; }
    .empresa { text-align: right; }
    .empresa .nombre { font-size: 17px; font-weight: 800; letter-spacing: .02em; }
    .empresa div { font-size: 11px; color: #444; }

    .banda {
        margin: 18px 0 14px; padding: 10px 14px; border-radius: 6px;
        background: #1a1a1a; color: #fff;
        display: flex; justify-content: space-between; align-items: baseline;
    }
    .banda .titulo { font-size: 16px; font-weight: 800; letter-spacing: .12em; }
    .banda .numero { font-size: 14px; font-weight: 700; }

    .meta { display: flex; gap: 14px; margin-bottom: 16px; }
    .tarjeta {
        flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px;
    }
    .tarjeta h3 {
        font-size: 10px; text-transform: uppercase; letter-spacing: .1em;
        color: #888; margin-bottom: 6px;
    }
    .tarjeta .dato { margin-bottom: 2px; }
    .tarjeta .dato strong { font-weight: 700; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items thead th {
        font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
        text-align: left; color: #666; border-bottom: 2px solid #1a1a1a;
        padding: 6px 8px;
    }
    table.items thead th.num, table.items tbody td.num { text-align: right; }
    table.items tbody td { padding: 7px 8px; border-bottom: 1px solid #e7e7e7; vertical-align: top; }
    table.items tbody tr:nth-child(even) { background: #fafafa; }

    /* El sello se va a la IZQUIERDA con margin-right:auto y los totales
       siguen pegados a la derecha. Sin sello cargado, la fila queda igual
       que siempre (el hueco de la izquierda simplemente no se llena). */
    .cierre { display: flex; justify-content: flex-end; align-items: flex-end; gap: 14px; }
    .sello { margin-right: auto; }
    .sello img { width: 118px; height: auto; }
    .totales { width: 260px; }
    .totales .fila { display: flex; justify-content: space-between; padding: 3px 8px; }
    .totales .fila.suave { color: #555; font-size: 11px; }
    /* El descuento es el argumento de venta de la cotización: va resaltado y
       subrayado para que el cliente lo vea de un vistazo. */
    .totales .fila.descuento {
        font-weight: 700; color: #146c2e;
        background: #eafaef; border-bottom: 2px solid #146c2e;
        border-radius: 4px; padding: 5px 8px;
    }
    .totales .fila.total {
        margin-top: 6px; padding: 8px; border-radius: 6px;
        background: #1a1a1a; color: #fff; font-size: 14px; font-weight: 800;
    }
    /* Total en letras, igual que en la factura: evita que alguien "corrija"
       el número a mano en el papel. */
    .en-letras {
        margin-top: 6px; padding: 0 8px; font-size: 10px; line-height: 1.35;
        text-align: right; color: #444;
    }
    .en-letras strong { color: #1a1a1a; }

    .anticipo {
        margin-top: 8px; padding: 7px 8px; border: 1px dashed #999; border-radius: 6px;
        font-size: 11px; text-align: center;
    }

    .notas { margin-top: 16px; padding: 10px 12px; background: #f6f6f6; border-radius: 6px; }
    .notas h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: #888; margin-bottom: 4px; }
    .notas p { white-space: pre-line; }

    .pie { margin-top: 22px; text-align: center; font-size: 10px; color: #777; }
    .pie .validez { font-weight: 700; color: #1a1a1a; margin-bottom: 3px; font-size: 11px; }

    /* El precio unitario repetido bajo la descripción: SOLO se usa en el
       teléfono, donde la columna propia no cabe. En la hoja no existe. */
    .unit-movil { display: none; }

    /* ------------------------------------------------------------------
       Vista del CLIENTE (link de WhatsApp, se abre en el teléfono).
       El documento está armado para una hoja carta: en un celular las filas
       flex se aplastan y la letra queda diminuta. Acá se acomoda. TODO va
       bajo body.cliente y dentro de @media screen, así el PDF de Browsershot
       y la impresión salen exactamente igual que antes.
    ------------------------------------------------------------------ */
    @media screen {
        body.cliente { background: #eceef1; padding: 12px 0 96px; }
        body.cliente .hoja {
            width: min(760px, calc(100% - 16px));
            margin: 0 auto; background: #fff;
            padding: 24px 22px; border-radius: 12px;
            box-shadow: 0 2px 14px rgba(0,0,0,.12);
        }
        /* Nombres largos de platillos: que partan en vez de desbordar. */
        body.cliente table.items td { word-break: break-word; }
        /* Los montos NO se parten: "L. 23,750.00" en dos renglones se lee
           como dos cifras distintas. La descripción cede el ancho. */
        body.cliente table.items .num { white-space: nowrap; }
    }

    /* Teléfono: la hoja carta no cabe en 390px. Se apila lo que allá va
       lado a lado y se sube la letra a tamaño legible. */
    @media screen and (max-width: 600px) {
        body.cliente { font-size: 13.5px; }
        body.cliente .hoja { padding: 16px 14px; }
        body.cliente .encabezado { flex-direction: column; align-items: center; text-align: center; }
        body.cliente .empresa { text-align: center; }
        body.cliente .empresa div { font-size: 12px; }
        body.cliente .banda { flex-direction: column; align-items: flex-start; gap: 3px; }
        body.cliente .meta { flex-direction: column; }
        body.cliente .cierre { display: block; }
        body.cliente .totales { width: 100%; }
        /* En el teléfono la fila se apila: el sello queda centrado arriba de
           los totales en vez de pelearse por el ancho. */
        body.cliente .sello { margin: 0 0 12px; text-align: center; }
        body.cliente .sello img { width: 96px; }
        body.cliente .tarjeta .dato { font-size: 13px; }
        body.cliente table.items thead th { font-size: 10.5px; }
        body.cliente table.items tbody td { padding: 8px 4px; }
        /* Cuatro columnas no entran: el unitario se va y reaparece como
           renglón chico bajo la descripción. El importe sí se queda. */
        body.cliente table.items .unit { display: none; }
        body.cliente .unit-movil { display: block; font-size: 11.5px; color: #666; margin-top: 1px; }
        body.cliente .totales .fila.total { font-size: 15px; }
    }
</style>
@if (($paraCliente ?? false))
    {{-- Impresión desde el TELÉFONO ("Guardar como PDF"): hoja normal, sin la
         tarjeta gris de pantalla y sin la barra de acciones. Va después del
         <style> de arriba a propósito — gana el último. --}}
    <style>
        @page { size: auto; margin: 12mm; }

        @media print {
            html, body { background: #fff; }
            body.cliente { padding: 0; }
            body.cliente .hoja {
                width: auto; margin: 0; padding: 0;
                background: transparent; box-shadow: none; border-radius: 0;
            }
            .acciones { display: none !important; }
        }

        @media screen {
            /* Barra fija abajo: el botón queda siempre a mano sin hacer scroll. */
            .acciones {
                position: fixed; left: 0; right: 0; bottom: 0; padding: 10px 14px;
                background: rgba(255,255,255,.97); border-top: 1px solid #e5e7eb;
                display: flex; flex-direction: column; align-items: center; gap: 5px;
                font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            }
            .acciones button {
                display: block; width: min(420px, 94vw); text-align: center;
                padding: 13px 16px; border: none; border-radius: 10px; background: #1a1a1a;
                color: #fff; font-weight: 700; font-size: 15px; cursor: pointer;
                font-family: inherit;
            }
            .acciones .nota { font-size: 11.5px; color: #6b7280; }
        }
    </style>
@endif
</head>
<body @if (($paraCliente ?? false)) class="cliente" @endif>
<div class="hoja">

    {{-- Encabezado: logo + datos de la empresa --}}
    <div class="encabezado">
        <div>
            @if ($logo)
                <img src="{{ $logo }}" alt="Logo" class="logo">
            @else
                <div class="empresa nombre" style="text-align:left; font-size:17px; font-weight:800;">{{ $empresa['nombre'] }}</div>
            @endif
        </div>
        <div class="empresa">
            <div class="nombre">{{ $empresa['nombre'] }}</div>
            @if ($empresa['razon_social'] && $empresa['razon_social'] !== $empresa['nombre'])
                <div>{{ $empresa['razon_social'] }}</div>
            @endif
            <div>RTN: {{ $empresa['rtn'] }}</div>
            <div>{{ $empresa['direccion'] }}</div>
            @if ($empresa['telefono'])<div>Tel: {{ $empresa['telefono'] }}</div>@endif
            @if ($empresa['correo'])<div>{{ $empresa['correo'] }}</div>@endif
        </div>
    </div>

    {{-- Banda de título --}}
    <div class="banda">
        <span class="titulo">COTIZACIÓN DE EVENTO</span>
        <span class="numero">{{ $c->numero }} · {{ $c->created_at?->format('d/m/Y') }}</span>
    </div>

    {{-- Cliente + evento --}}
    <div class="meta">
        <div class="tarjeta">
            <h3>Cliente</h3>
            <div class="dato"><strong>{{ $c->cliente_nombre }}</strong></div>
            @if ($c->cliente_telefono)<div class="dato">Tel: {{ $c->cliente_telefono }}</div>@endif
            @if ($c->cliente_rtn)<div class="dato">RTN: {{ $c->cliente_rtn }}</div>@endif
        </div>
        <div class="tarjeta">
            <h3>Evento</h3>
            <div class="dato"><strong>Fecha:</strong> {{ $c->evento_fecha?->format('d/m/Y') ?? 'Por definir' }}</div>
            @if ($c->evento_lugar)<div class="dato"><strong>Lugar:</strong> {{ $c->evento_lugar }}</div>@endif
            @if ($c->personas)<div class="dato"><strong>Personas:</strong> {{ number_format($c->personas) }}</div>@endif
        </div>
    </div>

    {{-- Ítems --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:8%;" class="num">Cant.</th>
                <th>Descripción</th>
                <th style="width:16%;" class="num unit">Precio unit.</th>
                <th style="width:16%;" class="num">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($c->items as $item)
                <tr>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->cantidad, 2), '0'), '.') }}</td>
                    {{-- El span va PEGADO al texto a propósito: un salto de
                         línea acá mete un espacio y corre la descripción en el PDF. --}}
                    <td>{{ $item->descripcion }}<span class="unit-movil">L. {{ number_format((float) $item->precio_unitario, 2) }} c/u</span></td>
                    <td class="num unit">L. {{ number_format((float) $item->precio_unitario, 2) }}</td>
                    <td class="num">L. {{ number_format($item->importe(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales con desglose de ISV (precios con ISV incluido, se desglosa) --}}
    <div class="cierre">
        @if ($sello ?? null)
            {{-- Sello de hule del negocio: llena el blanco que queda frente a
                 los totales y hace que la hoja se lea como un documento
                 sellado, no como una impresión suelta. --}}
            <div class="sello"><img src="{{ $sello }}" alt="Sello del negocio"></div>
        @endif
        <div class="totales">
            <div class="fila"><span>Subtotal</span><span>L. {{ number_format((float) $c->subtotal, 2) }}</span></div>
            @if ((float) $c->descuento > 0)
                <div class="fila descuento"><span>Descuento</span><span>− L. {{ number_format((float) $c->descuento, 2) }}</span></div>
            @endif
            @if ((float) $c->exento > 0)
                <div class="fila suave"><span>Importe exento</span><span>L. {{ number_format((float) $c->exento, 2) }}</span></div>
            @endif
            <div class="fila suave"><span>Importe gravado</span><span>L. {{ number_format((float) $c->gravado, 2) }}</span></div>
            <div class="fila suave"><span>ISV ({{ rtrim(rtrim(number_format($tasaIsv * 100, 2), '0'), '.') }}%) incluido</span><span>L. {{ number_format((float) $c->isv, 2) }}</span></div>
            <div class="fila total"><span>TOTAL</span><span>L. {{ number_format((float) $c->total, 2) }}</span></div>
            <div class="en-letras">Son: <strong>{{ \App\Support\NumeroALetras::convertir((float) $c->total) }}</strong></div>
            @if ($c->anticipo !== null && (float) $c->anticipo > 0)
                <div class="anticipo">Anticipo para reservar la fecha: <strong>L. {{ number_format((float) $c->anticipo, 2) }}</strong></div>
            @endif
        </div>
    </div>

    {{-- Notas / condiciones --}}
    @if ($c->notas)
        <div class="notas">
            <h3>Notas y condiciones</h3>
            <p>{{ $c->notas }}</p>
        </div>
    @endif

    <div class="pie">
        <div class="validez">Precios válidos hasta el {{ $c->validaHasta()->format('d/m/Y') }} ({{ $c->validez_dias }} días).</div>
        <div>Esta cotización no es un documento fiscal. ¡Gracias por preferirnos!</div>
    </div>
</div>

@if (($paraCliente ?? false))
    {{-- Guardar la cotización NO pasa por el servidor: se abre el diálogo de
         impresión del teléfono y ahí el propio sistema ofrece "Guardar como
         PDF". Antes el link mandaba a una ruta que levantaba Chromium para
         armar el archivo: tardaba y el cliente veía una pantalla en blanco. --}}
    <div class="acciones">
        <button type="button" onclick="window.print()">Descargar PDF</button>
        <div class="nota">En el diálogo elegí “Guardar como PDF”</div>
    </div>
@endif
</body>
</html>
