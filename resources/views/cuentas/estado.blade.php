<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- Sin esto el teléfono renderiza a 980px y el saldo se ve como un sello. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de cuenta — {{ $cuenta->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 14px 0 90px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f3f4f6; color: #111827;
        }
        .hoja {
            width: min(680px, 94vw); margin: 0 auto; background: #fff;
            border-radius: 14px; box-shadow: 0 2px 16px rgba(0,0,0,.10); overflow: hidden;
        }
        .encabezado { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid #eef0f3; }
        .encabezado img { width: 54px; height: 54px; object-fit: contain; }
        .negocio { font-weight: 800; font-size: 15px; }
        .negocio div { font-weight: 500; font-size: 12px; color: #6b7280; }

        /* El saldo es lo único que el cliente vino a ver: va grande y primero. */
        .saldo { padding: 22px 18px; text-align: center; background: #111827; color: #fff; }
        .saldo .rotulo { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; opacity: .75; }
        .saldo .monto { font-size: 40px; font-weight: 800; line-height: 1.1; margin-top: 4px; white-space: nowrap; }
        .saldo .rojo { color: #fca5a5; }
        .saldo .cuenta { margin-top: 8px; font-size: 13px; opacity: .85; text-transform: uppercase; }

        .resumen { display: flex; border-bottom: 1px solid #eef0f3; }
        .resumen div { flex: 1; padding: 14px 10px; text-align: center; }
        .resumen div + div { border-left: 1px solid #eef0f3; }
        .resumen .rotulo { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .resumen .valor { font-size: 17px; font-weight: 700; margin-top: 3px; white-space: nowrap; }
        .verde { color: #15803d; }

        .titulo { padding: 14px 18px 6px; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: #6b7280; }
        .mov { display: flex; align-items: flex-start; gap: 10px; padding: 11px 18px; border-top: 1px solid #f3f4f6; }
        .mov .izq { flex: 1; min-width: 0; }
        .mov .tipo { font-weight: 700; font-size: 14px; }
        .mov .detalle { font-size: 12px; color: #6b7280; margin-top: 2px; word-break: break-word; }
        .mov .der { text-align: right; white-space: nowrap; }
        .mov .monto { font-weight: 800; font-size: 15px; }
        .mov .despues { font-size: 11px; color: #9ca3af; margin-top: 2px; }

        .vacio { padding: 26px 18px; text-align: center; color: #6b7280; font-size: 14px; }
        .pie { padding: 16px 18px 20px; font-size: 11.5px; color: #6b7280; text-align: center; line-height: 1.5; }

        .acciones { position: fixed; left: 0; right: 0; bottom: 0; padding: 12px; background: rgba(243,244,246,.95); border-top: 1px solid #e5e7eb; }
        .acciones button {
            display: block; width: min(680px, 94vw); margin: 0 auto; padding: 14px;
            border: 0; border-radius: 10px; background: #111827; color: #fff;
            font-size: 15px; font-weight: 700; cursor: pointer;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .hoja { width: 100%; box-shadow: none; border-radius: 0; }
            .acciones { display: none; }
            .saldo { background: #fff; color: #111827; border-bottom: 2px solid #111827; }
            .saldo .rojo { color: #b91c1c; }
        }
    </style>
</head>
<body>
<div class="hoja">
    <div class="encabezado">
        @if ($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="negocio">
            {{ $empresa['nombre'] }}
            @if ($empresa['telefono'])<div>Tel: {{ $empresa['telefono'] }}</div>@endif
            @if ($empresa['correo'])<div>{{ $empresa['correo'] }}</div>@endif
        </div>
    </div>

    @php($saldo = (float) $cuenta->saldo)
    <div class="saldo">
        <div class="rotulo">Saldo disponible</div>
        <div class="monto {{ $saldo < 0 ? 'rojo' : '' }}">L. {{ number_format($saldo, 2) }}</div>
        <div class="cuenta">{{ $cuenta->nombre }}@if ($cuenta->rtn()) · RTN {{ $cuenta->rtn() }}@endif</div>
        @if ($saldo < 0)
            <div class="cuenta">Saldo en rojo: pendiente de reponer</div>
        @endif
    </div>

    <div class="resumen">
        <div>
            <div class="rotulo">Depositado</div>
            <div class="valor verde">L. {{ number_format($cuenta->totalDepositado(), 2) }}</div>
        </div>
        <div>
            <div class="rotulo">Consumido</div>
            <div class="valor">L. {{ number_format($cuenta->totalConsumido(), 2) }}</div>
        </div>
    </div>

    <div class="titulo">Movimientos</div>

    @forelse ($movimientos as $mov)
        @php($monto = (float) $mov->monto)
        <div class="mov">
            <div class="izq">
                <div class="tipo">{{ $mov->tipoLabel() }}</div>
                <div class="detalle">
                    {{ $mov->created_at->format('d/m/Y h:i A') }}
                    @if ($mov->formaLabel()) · {{ $mov->formaLabel() }} @endif
                    @if ($mov->venta?->factura?->numero) · Factura {{ $mov->venta->factura->numero }} @endif
                    @if ($mov->notas) · {{ $mov->notas }} @endif
                </div>
            </div>
            <div class="der">
                <div class="monto {{ $monto >= 0 ? 'verde' : '' }}">
                    {{ $monto >= 0 ? '+' : '−' }} L. {{ number_format(abs($monto), 2) }}
                </div>
                <div class="despues">Saldo: L. {{ number_format((float) $mov->saldo_despues, 2) }}</div>
            </div>
        </div>
    @empty
        <div class="vacio">Todavía no hay movimientos en esta cuenta.</div>
    @endforelse

    <div class="pie">
        @if ($movimientos->count() >= $tope)
            Se muestran los últimos {{ $tope }} movimientos. El saldo y los totales incluyen todos.<br>
        @endif
        Esta página no es un documento fiscal: cada consumo tiene su factura.<br>
        Si algo no cuadra, escribinos y lo revisamos.
    </div>
</div>

{{-- Guardar no pasa por el servidor: el propio teléfono ofrece "Guardar como
     PDF" en su diálogo de impresión. Mismo criterio que factura y cotización. --}}
<div class="acciones">
    <button type="button" onclick="window.print()">Descargar o imprimir</button>
</div>
</body>
</html>
