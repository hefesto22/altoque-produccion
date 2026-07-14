<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
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

    .cierre { display: flex; justify-content: flex-end; gap: 14px; }
    .totales { width: 260px; }
    .totales .fila { display: flex; justify-content: space-between; padding: 3px 8px; }
    .totales .fila.suave { color: #555; font-size: 11px; }
    .totales .fila.total {
        margin-top: 6px; padding: 8px; border-radius: 6px;
        background: #1a1a1a; color: #fff; font-size: 14px; font-weight: 800;
    }
    .anticipo {
        margin-top: 8px; padding: 7px 8px; border: 1px dashed #999; border-radius: 6px;
        font-size: 11px; text-align: center;
    }

    .notas { margin-top: 16px; padding: 10px 12px; background: #f6f6f6; border-radius: 6px; }
    .notas h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: #888; margin-bottom: 4px; }
    .notas p { white-space: pre-line; }

    .pie { margin-top: 22px; text-align: center; font-size: 10px; color: #777; }
    .pie .validez { font-weight: 700; color: #1a1a1a; margin-bottom: 3px; font-size: 11px; }
</style>
</head>
<body>

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
                <th style="width:16%;" class="num">Precio unit.</th>
                <th style="width:16%;" class="num">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($c->items as $item)
                <tr>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->cantidad, 2), '0'), '.') }}</td>
                    <td>{{ $item->descripcion }}</td>
                    <td class="num">L. {{ number_format((float) $item->precio_unitario, 2) }}</td>
                    <td class="num">L. {{ number_format($item->importe(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales con desglose de ISV (precios con ISV incluido, se desglosa) --}}
    <div class="cierre">
        <div class="totales">
            <div class="fila"><span>Subtotal</span><span>L. {{ number_format((float) $c->subtotal, 2) }}</span></div>
            @if ((float) $c->descuento > 0)
                <div class="fila"><span>Descuento</span><span>− L. {{ number_format((float) $c->descuento, 2) }}</span></div>
            @endif
            @if ((float) $c->exento > 0)
                <div class="fila suave"><span>Importe exento</span><span>L. {{ number_format((float) $c->exento, 2) }}</span></div>
            @endif
            <div class="fila suave"><span>Importe gravado</span><span>L. {{ number_format((float) $c->gravado, 2) }}</span></div>
            <div class="fila suave"><span>ISV ({{ rtrim(rtrim(number_format($tasaIsv * 100, 2), '0'), '.') }}%) incluido</span><span>L. {{ number_format((float) $c->isv, 2) }}</span></div>
            <div class="fila total"><span>TOTAL</span><span>L. {{ number_format((float) $c->total, 2) }}</span></div>
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

</body>
</html>
