<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Verificación de factura · {{ $empresa['nombre'] }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #eef1f5; color: #1e293b;
            display: flex; flex-direction: column; align-items: center;
            min-height: 100vh; padding: 1.25rem .75rem 2rem;
        }
        .wrap { width: 100%; max-width: 540px; }

        /* ── Banner de estado ── */
        .banner { border-radius: .75rem .75rem 0 0; padding: .9rem 1rem; text-align: center; color: #fff; }
        .banner .titulo { font-size: 1.05rem; font-weight: 800; letter-spacing: .02em; }
        .banner .sub { font-size: .78rem; opacity: .92; margin-top: .15rem; }
        .banner.ok { background: linear-gradient(135deg, #047857, #059669); }
        .banner.anulada { background: linear-gradient(135deg, #b91c1c, #dc2626); }
        .banner.invalida { background: linear-gradient(135deg, #475569, #64748b); }

        /* ── Documento (papel) ── */
        .doc {
            position: relative; overflow: hidden;
            background: #fff; border: 1px solid #d8dee9; border-top: 0;
            border-radius: 0 0 .75rem .75rem;
            padding: 1.5rem 1.35rem;
            box-shadow: 0 12px 32px rgba(15,23,42,.12);
            font-size: .9rem;
        }
        .marca-agua {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            pointer-events: none; z-index: 1;
        }
        .marca-agua span {
            font-size: 3.2rem; font-weight: 900; color: rgba(220,38,38,.10);
            transform: rotate(-24deg); letter-spacing: .1em; white-space: nowrap;
        }
        .contenido { position: relative; z-index: 2; }

        .center { text-align: center; }
        .sm { font-size: .8rem; }
        .xs { font-size: .72rem; }
        .muted { color: #64748b; }
        .bold { font-weight: 700; }
        h1 { font-size: 1.15rem; font-weight: 800; }

        .hr { border: 0; border-top: 1px dashed #cbd5e1; margin: .85rem 0; }
        .hr2 { border: 0; border-top: 2px solid #334155; margin: .85rem 0; }

        table { width: 100%; border-collapse: collapse; }
        td { padding: .18rem 0; vertical-align: top; }
        td.right { text-align: right; }
        td.lbl { color: #64748b; }

        .num-factura { font-size: 1.3rem; font-weight: 800; letter-spacing: .03em; font-variant-numeric: tabular-nums; }

        .caja-sar {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .5rem;
            padding: .7rem .8rem; font-size: .8rem; margin: .85rem 0;
        }
        .caja-sar .titulo-caja { font-size: .68rem; font-weight: 700; letter-spacing: .08em; color: #64748b; margin-bottom: .35rem; }
        .mono { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; word-break: break-all; }

        .items td { padding: .25rem 0; border-bottom: 1px solid #f1f5f9; }
        .items tr.cab td { border-bottom: 1px solid #cbd5e1; font-weight: 700; font-size: .78rem; color: #475569; }

        .tot td { padding: .16rem 0; font-size: .86rem; font-variant-numeric: tabular-nums; }
        .tot tr.total td { font-size: 1.05rem; font-weight: 800; border-top: 2px solid #334155; padding-top: .45rem; }

        .aviso-anulada {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            border-radius: .5rem; padding: .7rem .8rem; font-size: .82rem; margin: .85rem 0;
        }

        /* ── Comprobaciones de autenticidad ── */
        .checks { margin-top: 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: .5rem; padding: .75rem .85rem; }
        .checks.neutro { background: #f8fafc; border-color: #e2e8f0; }
        .checks .titulo-caja { font-size: .68rem; font-weight: 700; letter-spacing: .08em; color: #166534; margin-bottom: .4rem; }
        .checks ul { list-style: none; padding: 0; }
        .checks li { display: flex; gap: .45rem; align-items: flex-start; font-size: .8rem; padding: .14rem 0; color: #14532d; }
        .checks li.falla { color: #b45309; }
        .checks li .ic { flex: 0 0 auto; font-weight: 800; }

        .foot { margin-top: 1.1rem; text-align: center; font-size: .72rem; color: #94a3b8; line-height: 1.5; }

        @media print {
            body { background: #fff; padding: 0; }
            .doc { box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="wrap">

@if ($factura === null)
    <div class="banner invalida">
        <div class="titulo">✕ Documento no encontrado</div>
        <div class="sub">Verificación de autenticidad · {{ $empresa['nombre'] }}</div>
    </div>
    <div class="doc">
        <div class="contenido center" style="padding: 1rem 0;">
            <p class="bold" style="margin-bottom:.5rem;">El código consultado no corresponde a ninguna factura emitida por {{ $empresa['nombre'] }}.</p>
            <p class="sm muted">El documento que lo presenta podría ser inválido o falsificado. Si usted recibió una factura física con este código QR, repórtelo al establecimiento (Tel: {{ $empresa['telefono'] }}).</p>
        </div>
        <div class="foot">Consulta realizada el {{ $verificadaAt->format('d/m/Y') }} a las {{ $verificadaAt->format('h:i A') }}</div>
    </div>
@else
    @php
        $f = $factura;
        $descuento = (float) $f->descuento;
        $subtotal = (float) $f->subtotal_lista > 0 ? (float) $f->subtotal_lista : (float) $f->total + $descuento;
        $emitidaEnPlazo = $f->emitida_at->lte($f->cai->fecha_limite_emision->endOfDay());
        $correlativoEnRango = $f->correlativo >= $f->cai->correlativo_desde && $f->correlativo <= $f->cai->correlativo_hasta;
    @endphp

    @if ($f->anulada)
        <div class="banner anulada">
            <div class="titulo">⚠ FACTURA ANULADA</div>
            <div class="sub">Documento auténtico, pero sin validez fiscal por anulación del emisor</div>
        </div>
    @else
        <div class="banner ok">
            <div class="titulo">✓ FACTURA VÁLIDA Y VIGENTE</div>
            <div class="sub">Autenticidad verificada contra los registros del emisor</div>
        </div>
    @endif

    <div class="doc">
        @if ($f->anulada)
            <div class="marca-agua"><span>A N U L A D A</span></div>
        @endif
        <div class="contenido">

            {{-- ───── Emisor ───── --}}
            <div class="center">
                <h1>{{ $empresa['nombre'] }}</h1>
                @if ($empresa['nombre_comercial'] && $empresa['razon_social'] !== $empresa['nombre_comercial'])
                    <div class="sm muted">{{ $empresa['razon_social'] }}</div>
                @endif
                <div class="sm">RTN: <span class="bold">{{ $empresa['rtn'] }}</span></div>
                <div class="sm muted">{{ $empresa['direccion'] }}</div>
                <div class="sm muted">Tel: {{ $empresa['telefono'] }}@if($empresa['correo']) · {{ $empresa['correo'] }}@endif</div>
            </div>

            <div class="hr2"></div>
            <div class="center bold sm" style="letter-spacing:.12em;">FACTURA</div>
            <div class="center num-factura">{{ $f->numero }}</div>
            <div class="hr2"></div>

            @if ($f->anulada)
                <div class="aviso-anulada">
                    <span class="bold">Anulada el {{ $f->anulada_at?->format('d/m/Y') }} a las {{ $f->anulada_at?->format('h:i A') }}.</span>
                    @if ($f->motivo_anulacion) Motivo: {{ $f->motivo_anulacion }}. @endif
                    Este documento no ampara crédito fiscal ni gasto deducible.
                </div>
            @endif

            {{-- ───── Autorización SAR ───── --}}
            <div class="caja-sar">
                <div class="titulo-caja">AUTORIZACIÓN SAR — AUTOIMPRESOR</div>
                <div class="sm">C.A.I.:</div>
                <div class="mono bold sm">{{ $f->cai->codigo }}</div>
                <table class="sm" style="margin-top:.4rem;">
                    <tr><td class="lbl">Rango autorizado</td>
                        <td class="right">{{ $f->cai->prefijo() }}-{{ str_pad((string) $f->cai->correlativo_desde, 8, '0', STR_PAD_LEFT) }}
                            al {{ $f->cai->prefijo() }}-{{ str_pad((string) $f->cai->correlativo_hasta, 8, '0', STR_PAD_LEFT) }}</td></tr>
                    <tr><td class="lbl">Fecha límite de emisión</td>
                        <td class="right">{{ $f->cai->fecha_limite_emision->format('d/m/Y') }}</td></tr>
                </table>
            </div>

            {{-- ───── Documento y cliente ───── --}}
            <table>
                <tr><td class="lbl">Fecha y hora de emisión</td><td class="right bold">{{ $f->emitida_at->format('d/m/Y h:i A') }}</td></tr>
                <tr><td class="lbl">Cliente</td><td class="right bold">{{ $f->nombre_cliente }}</td></tr>
                <tr><td class="lbl">RTN / Identidad</td><td class="right bold">{{ $f->rtn_cliente ?? 'Consumidor Final' }}</td></tr>
            </table>

            <div class="hr"></div>

            {{-- ───── Detalle ───── --}}
            <table class="items">
                <tr class="cab">
                    <td style="width:9%;">Ct</td>
                    <td>Descripción</td>
                    <td class="right" style="width:21%;">P. Unit</td>
                    <td class="right" style="width:23%;">Importe</td>
                </tr>
                @if ($detallada && $f->venta)
                    @foreach ($f->venta->items as $item)
                        @if (! empty($item->componentes) && (float) $item->precio_lista > 0)
                            @foreach ($item->componentes as $c)
                                @php
                                    $cant = (int) ($c['cantidad'] ?? 1) * (int) $item->cantidad;
                                    $pu = (float) ($c['precio'] ?? 0);
                                @endphp
                                <tr>
                                    <td>{{ $cant }}</td>
                                    <td>{{ $c['nombre'] }}</td>
                                    <td class="right">{{ number_format($pu, 2) }}</td>
                                    <td class="right">{{ number_format($pu * $cant, 2) }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td>{{ $item->cantidad }}</td>
                                <td>{{ $item->nombre }}</td>
                                <td class="right">{{ number_format((float) $item->precio_unitario, 2) }}</td>
                                <td class="right">{{ number_format((float) $item->importe, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach
                @else
                    <tr>
                        <td>1</td>
                        <td>{{ $empresa['factura_concepto'] }}</td>
                        <td class="right">{{ number_format($subtotal, 2) }}</td>
                        <td class="right">{{ number_format($subtotal, 2) }}</td>
                    </tr>
                @endif
            </table>

            {{-- ───── Totales (desglose SAR) ───── --}}
            <table class="tot" style="margin-top:.7rem;">
                <tr><td class="lbl">Subtotal</td><td class="right">L. {{ number_format($subtotal, 2) }}</td></tr>
                <tr><td class="lbl">Descuentos y rebajas otorgados</td><td class="right">L. {{ number_format($descuento, 2) }}</td></tr>
                <tr><td class="lbl">Importe exento</td><td class="right">L. {{ number_format((float) $f->exento, 2) }}</td></tr>
                <tr><td class="lbl">Importe exonerado</td><td class="right">L. 0.00</td></tr>
                <tr><td class="lbl">Importe gravado 15%</td><td class="right">L. {{ number_format((float) $f->gravado, 2) }}</td></tr>
                <tr><td class="lbl">Importe gravado 18%</td><td class="right">L. 0.00</td></tr>
                <tr><td class="lbl">I.S.V. 15%</td><td class="right">L. {{ number_format((float) $f->isv, 2) }}</td></tr>
                <tr><td class="lbl">I.S.V. 18%</td><td class="right">L. 0.00</td></tr>
                <tr class="total"><td>TOTAL</td><td class="right">L. {{ number_format((float) $f->total, 2) }}</td></tr>
            </table>

            <div class="sm bold" style="margin-top:.55rem;">Son: {{ \App\Support\NumeroALetras::convertir((float) $f->total) }}</div>

            {{-- ───── Comprobaciones de autenticidad ───── --}}
            <div class="checks @if($f->anulada) neutro @endif">
                <div class="titulo-caja">COMPROBACIONES DE AUTENTICIDAD</div>
                <ul>
                    <li><span class="ic">✓</span> Documento localizado en los registros del emisor</li>
                    <li><span class="ic">✓</span> Código de verificación auténtico (firma criptográfica HMAC-SHA256)</li>
                    @if ($correlativoEnRango)
                        <li><span class="ic">✓</span> Correlativo dentro del rango autorizado por el SAR</li>
                    @else
                        <li class="falla"><span class="ic">!</span> Correlativo fuera del rango autorizado — consulte al emisor</li>
                    @endif
                    @if ($emitidaEnPlazo)
                        <li><span class="ic">✓</span> Emitida dentro de la fecha límite del C.A.I. ({{ $f->cai->fecha_limite_emision->format('d/m/Y') }})</li>
                    @else
                        <li class="falla"><span class="ic">!</span> Emitida después de la fecha límite del C.A.I. — consulte al emisor</li>
                    @endif
                    @if ($f->anulada)
                        <li class="falla"><span class="ic">!</span> Documento ANULADO por el emisor el {{ $f->anulada_at?->format('d/m/Y') }}</li>
                    @endif
                </ul>
            </div>

            <div class="foot">
                Este documento es una representación de la factura emitida por un autoimpresor autorizado por el Servicio de Administración de Rentas (SAR), conforme al Reglamento del Régimen de Facturación (Acuerdo 481-2017).<br>
                Código de verificación: <span class="mono">{{ substr($f->hash_verificacion, 0, 24) }}…</span><br>
                Consulta realizada el {{ $verificadaAt->format('d/m/Y') }} a las {{ $verificadaAt->format('h:i A') }} · {{ $empresa['nombre'] }}<br>
                "La factura es beneficio de todos, ¡EXÍJALA!"
            </div>

        </div>
    </div>
@endif

</div>
</body>
</html>
