<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte de caja #{{ $corte->id }}</title>
    <style>
        /* Ticket térmico 80mm: mismo lenguaje visual que la comanda. */
        @page { size: 80mm auto; margin: 2mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            width: 72mm;
            color: #000;
            font-size: 12px;
            line-height: 1.35;
            font-weight: 700;
            -webkit-text-stroke: 0.25px #000;
        }
        .center { text-align: center; }
        .grande { font-size: 18px; font-weight: 700; }
        .medio  { font-size: 14px; font-weight: 700; }
        .chico  { font-size: 10px; }
        .sep    { border-top: 1px dashed #000; margin: 6px 0; }
        .titulo { font-size: 11px; text-transform: uppercase; margin-bottom: 2px; }
        table   { width: 100%; border-collapse: collapse; }
        td      { vertical-align: top; padding: 1px 0; }
        td.der  { text-align: right; white-space: nowrap; }
        td.sub  { padding-left: 8px; font-size: 11px; }
        .banner {
            border: 2px solid #000; text-align: center; font-weight: 700;
            font-size: 14px; padding: 4px; margin-top: 6px;
        }
        .firma  { margin-top: 26px; border-top: 1px solid #000; text-align: center; font-size: 10px; padding-top: 2px; }
    </style>
</head>
{{-- Auto-print solo si se abre directo (el panel lo imprime vía iframe; sin
     este guard saldrían dos diálogos de impresión). --}}
<body onload="if (window.self === window.top) window.print()">
@php
    $empresa  = \App\Models\EmpresaSetting::actual();
    $cerrado  = $corte->estado === 'cerrado';
    $esperado = $corte->efectivoEsperado();
    $dif      = $corte->diferencia !== null ? (float) $corte->diferencia : null;
@endphp

    <div class="center medio">{{ mb_strtoupper($empresa->nombreMostrar()) }}</div>
    <div class="center grande">CORTE DE CAJA</div>
    <div class="center">TURNO #{{ $corte->id }} · {{ mb_strtoupper($corte->cajero?->name ?? '—') }}</div>

    <div class="sep"></div>

    <table>
        <tr><td>ABIERTO</td><td class="der">{{ $corte->abierto_at?->format('d/m/Y h:i A') }}</td></tr>
        <tr><td>CERRADO</td><td class="der">{{ $corte->cerrado_at?->format('d/m/Y h:i A') ?? '—' }}</td></tr>
        @if ($corte->cierre_automatico)
            <tr><td colspan="2">(CIERRE AUTOMÁTICO)</td></tr>
        @endif
    </table>

    <div class="sep"></div>

    <div class="titulo">Ventas del turno</div>
    <table>
        <tr><td>VENTAS</td><td class="der">{{ $corte->cantidad_ventas }}</td></tr>
        <tr><td>TOTAL VENDIDO</td><td class="der">L. {{ number_format((float) $corte->total_ventas, 2) }}</td></tr>
        <tr><td>ISV DEL TURNO</td><td class="der">L. {{ number_format((float) $corte->total_isv, 2) }}</td></tr>
    </table>

    <div class="sep"></div>

    {{-- Totales por método desde venta_pagos: el mixto ya está repartido
         acá (su porción efectivo suma en EFECTIVO, etc.). El conteo entre
         paréntesis es de VENTAS enteras por forma de pago. --}}
    <div class="titulo">Recibido por forma de pago</div>
    <table>
        <tr><td>EFECTIVO ({{ (int) ($conteos['efectivo'] ?? 0) }} VENTAS)</td><td class="der">L. {{ number_format((float) $corte->total_efectivo, 2) }}</td></tr>
        <tr><td>TARJETA ({{ (int) ($conteos['tarjeta'] ?? 0) }} VENTAS)</td><td class="der">L. {{ number_format((float) $corte->total_tarjeta, 2) }}</td></tr>
        @foreach ($tarjetaBanco as $b)
            <tr><td class="sub">· {{ mb_strtoupper($b['banco']) }}</td><td class="der chico">L. {{ number_format($b['total'], 2) }}</td></tr>
        @endforeach
        <tr><td>TRANSFERENCIA ({{ (int) ($conteos['transferencia'] ?? 0) }} VENTAS)</td><td class="der">L. {{ number_format((float) $corte->total_transferencia, 2) }}</td></tr>
        @foreach ($transferBanco as $b)
            <tr><td class="sub">· {{ mb_strtoupper($b['banco']) }}</td><td class="der chico">L. {{ number_format($b['total'], 2) }}</td></tr>
        @endforeach
        {{-- Saldo a favor: la venta es de hoy, pero el dinero entró el día del
             depósito. No está en la gaveta ni en el terminal — va aparte para
             que la suma de arriba cuadre con el total de ventas. --}}
        @if ((float) $corte->total_saldo > 0)
            <tr><td>SALDO A FAVOR (CUENTAS)</td><td class="der">L. {{ number_format((float) $corte->total_saldo, 2) }}</td></tr>
            <tr><td colspan="2" class="chico">NO ES DINERO DE HOY: SE DEPOSITÓ ANTES.</td></tr>
        @endif
        @if ($transferSinBanco > 0)
            <tr><td class="sub">· SIN BANCO</td><td class="der chico">L. {{ number_format($transferSinBanco, 2) }}</td></tr>
        @endif
    </table>

    @if ((int) $mixto->ventas > 0)
        <div class="sep"></div>
        <div class="titulo">Pagos mixtos ({{ (int) $mixto->ventas }} {{ (int) $mixto->ventas === 1 ? 'venta' : 'ventas' }})</div>
        <table>
            <tr><td>TOTAL EN MIXTO</td><td class="der">L. {{ number_format((float) $mixto->total, 2) }}</td></tr>
            <tr><td class="sub">· EFECTIVO</td><td class="der chico">L. {{ number_format((float) $mixto->efectivo, 2) }}</td></tr>
            <tr><td class="sub">· TARJETA</td><td class="der chico">L. {{ number_format((float) $mixto->tarjeta, 2) }}</td></tr>
            <tr><td class="sub">· TRANSFERENCIA</td><td class="der chico">L. {{ number_format((float) $mixto->transferencia, 2) }}</td></tr>
        </table>
        <div class="chico">Estas porciones ya están sumadas en los totales de arriba.</div>
    @endif

    <div class="sep"></div>

    <div class="titulo">Efectivo en caja</div>
    <table>
        <tr><td>FONDO INICIAL</td><td class="der">L. {{ number_format((float) $corte->fondo_inicial, 2) }}</td></tr>
        <tr><td>+ VENTAS EN EFECTIVO</td><td class="der">L. {{ number_format((float) $corte->total_efectivo, 2) }}</td></tr>
        <tr><td>= ESPERADO</td><td class="der">L. {{ number_format($esperado, 2) }}</td></tr>
        <tr><td>CONTADO</td><td class="der">{{ $corte->efectivo_contado !== null ? 'L. '.number_format((float) $corte->efectivo_contado, 2) : '—' }}</td></tr>
    </table>

    @if ($dif === null)
        <div class="banner">POR REVISAR · NO SE CONTÓ LA GAVETA</div>
    @elseif ($dif === 0.0)
        <div class="banner">CAJA CUADRADA · L. 0.00</div>
    @else
        <div class="banner">{{ $dif > 0 ? 'SOBRANTE' : 'FALTANTE' }} · L. {{ number_format(abs($dif), 2) }}</div>
    @endif

    <div class="sep"></div>

    <div class="titulo">Terminal POS</div>
    <table>
        <tr><td>SALDO INICIAL</td><td class="der">L. {{ number_format((float) $corte->fondo_terminal, 2) }}</td></tr>
        <tr><td>NUEVO SALDO</td><td class="der">L. {{ number_format($cerrado && $corte->terminal_final !== null ? (float) $corte->terminal_final : (float) $corte->fondo_terminal + (float) $corte->total_tarjeta + (float) $corte->total_transferencia, 2) }}</td></tr>
    </table>

    {{-- Gran total del turno: efectivo esperado + nuevo saldo del terminal. --}}
    <table style="margin-top: 4px;">
        <tr class="medio"><td class="medio">TOTAL CAJA + TERMINAL</td><td class="der medio">L. {{ number_format($esperado + ($cerrado && $corte->terminal_final !== null ? (float) $corte->terminal_final : (float) $corte->fondo_terminal + (float) $corte->total_tarjeta + (float) $corte->total_transferencia), 2) }}</td></tr>
    </table>

    @if ($domViajeTransfer > 0)
        <div class="sep"></div>
        <div class="titulo">Repartidores</div>
        <table>
            <tr><td>A PAGAR (VIAJES DOMICILIO)</td><td class="der">L. {{ number_format($domViajeTransfer, 2) }}</td></tr>
        </table>
        <div class="chico">Domicilios pagados por transferencia: pagarle el viaje al repartidor.</div>
    @endif

    @if (! empty($corte->notas))
        <div class="sep"></div>
        <div class="titulo">Notas</div>
        <div class="chico">{{ $corte->notas }}</div>
    @endif

    <div class="firma">CAJERO</div>
    <div class="firma">REVISADO POR</div>

    <div class="sep"></div>
    <div class="center chico">Impreso {{ now()->format('d/m/Y h:i A') }}</div>
</body>
</html>
