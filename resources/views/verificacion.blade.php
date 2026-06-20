<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación de factura</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 1rem; }
        .card { background: #1e293b; border-radius: 1rem; padding: 1.75rem; max-width: 420px; width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,.4); }
        .estado { text-align: center; padding: .75rem; border-radius: .75rem; font-weight: 700; font-size: 1.1rem; margin-bottom: 1.25rem; }
        .ok { background: rgba(16,185,129,.15); color: #34d399; border: 1px solid #34d399; }
        .anulada { background: rgba(239,68,68,.15); color: #f87171; border: 1px solid #f87171; }
        .invalida { background: rgba(239,68,68,.15); color: #f87171; border: 1px solid #f87171; }
        h1 { font-size: 1.15rem; margin: 0 0 1rem; text-align: center; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        td { padding: .35rem 0; vertical-align: top; }
        td.lbl { color: #94a3b8; width: 45%; }
        td.val { text-align: right; font-weight: 600; }
        .foot { margin-top: 1.25rem; text-align: center; font-size: .78rem; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        @if ($factura === null)
            <div class="estado invalida">✕ Factura no encontrada</div>
            <p style="text-align:center; color:#94a3b8;">El código no corresponde a ninguna factura emitida por este sistema. Podría ser inválido o falsificado.</p>
        @else
            @if ($factura->anulada)
                <div class="estado anulada">⚠ Factura ANULADA</div>
            @else
                <div class="estado ok">✓ Factura válida y vigente</div>
            @endif

            <h1>{{ config('empresa.nombre') }}</h1>

            <table>
                <tr><td class="lbl">N° de factura</td><td class="val">{{ $factura->numero }}</td></tr>
                <tr><td class="lbl">CAI</td><td class="val" style="font-size:.7rem; word-break:break-all;">{{ $factura->cai->codigo }}</td></tr>
                <tr><td class="lbl">Fecha de emisión</td><td class="val">{{ $factura->emitida_at->format('d/m/Y H:i') }}</td></tr>
                <tr><td class="lbl">RTN cliente</td><td class="val">{{ $factura->rtn_cliente ?? 'Consumidor Final' }}</td></tr>
                <tr><td class="lbl">Cliente</td><td class="val">{{ $factura->nombre_cliente }}</td></tr>
                <tr><td class="lbl">Total</td><td class="val">L. {{ number_format((float) $factura->total, 2) }}</td></tr>
                @if ($factura->anulada)
                    <tr><td class="lbl">Anulada el</td><td class="val">{{ $factura->anulada_at?->format('d/m/Y H:i') }}</td></tr>
                @endif
            </table>
        @endif

        <div class="foot">Verificación de autenticidad · {{ config('empresa.nombre') }}</div>
    </div>
</body>
</html>
