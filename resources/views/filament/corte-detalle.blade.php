@php
    // Totales EN VIVO desde las ventas del turno. cuentaEnCaja() excluye las
    // ventas con factura ANULADA: su dinero se devolvió o se recobró en la
    // venta corregida, sumarlas duplicaría el efectivo esperado.
    $caja       = fn () => $corte->ventas()->cuentaEnCaja();
    $cantidad   = $caja()->count();
    $totalVentas = (float) $caja()->sum('total');
    $totalIsv   = (float) $caja()->sum('isv');
    $efectivo   = (float) $caja()->where('forma_pago', 'efectivo')->sum('total');
    $tarjeta    = (float) $caja()->where('forma_pago', 'tarjeta')->sum('total');
    $transfer   = (float) $caja()->where('forma_pago', 'transferencia')->sum('total');
    $porBanco   = $caja()->where('forma_pago', 'transferencia')->whereNotNull('banco')
        ->selectRaw('banco, sum(total) as total')->groupBy('banco')->orderBy('banco')->get();
    $sinBanco   = (float) $caja()->where('forma_pago', 'transferencia')->whereNull('banco')->sum('total');
    $tarjetaBanco = $caja()->where('forma_pago', 'tarjeta')->whereNotNull('banco')
        ->selectRaw('banco, sum(total) as total')->groupBy('banco')->orderBy('banco')->get();

    $fondo      = (float) $corte->fondo_inicial;
    $esperado   = $fondo + $efectivo;
    $cerrado    = $corte->estado === 'cerrado';
    $contado    = (float) $corte->efectivo_contado;
    $diferencia = (float) $corte->diferencia;

    // Lo que la empresa le debe a los repartidores: viaje de los domicilios
    // pagados por transferencia (el efectivo el repartidor ya lo entrega).
    $domViajeTransfer = (float) $caja()->where('tipo_orden', 'domicilio')->where('forma_pago', 'transferencia')->sum('costo_viaje');
@endphp

{{-- x-data: visor de factura en overlay (Alpine puro, sin roundtrip al servidor).
     Clic en "Factura" de la tabla de abajo → iframe con el HTML instantáneo
     del ticket, encima del modal del corte, sin salir de Cortes de Caja. --}}
<div x-data="{ facturaUrl: null, facturaNumero: '' }" style="display:flex; flex-direction:column; gap:1rem; font-size:.9rem;">

    {{-- ───── Visor de factura (overlay sobre el modal del corte) ───── --}}
    <template x-if="facturaUrl">
        <div style="position:fixed; inset:0; z-index:80; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.6); padding:1rem;"
            @click.self="facturaUrl = null">
            <div style="width:100%; max-width:26rem; display:flex; flex-direction:column; background:#fff; border-radius:.75rem; overflow:hidden; box-shadow:0 25px 50px rgba(0,0,0,.45);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem; padding:.6rem .9rem; border-bottom:1px solid rgba(0,0,0,.08); color:#111;">
                    <strong style="font-size:.85rem;" x-text="'Factura ' + facturaNumero"></strong>
                    <button type="button" @click="facturaUrl = null" title="Cerrar"
                        style="border:0; background:transparent; cursor:pointer; font-size:1.05rem; line-height:1; color:#111; padding:.15rem;">✕</button>
                </div>
                {{-- Fondo blanco fijo: el ticket está diseñado para papel. --}}
                <iframe :src="facturaUrl" style="display:block; width:100%; height:68vh; border:0; background:#fff;"></iframe>
            </div>
        </div>
    </template>

    {{-- ───── Encabezado ───── --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem; flex-wrap:wrap;">
        <div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
            <div><span style="opacity:.55;">Cajero</span><br><strong>{{ $corte->cajero?->name ?? '—' }}</strong></div>
            <div><span style="opacity:.55;">Abierto</span><br><strong>{{ $corte->abierto_at?->format('d/m/Y h:i A') }}</strong></div>
            <div><span style="opacity:.55;">Cerrado</span><br><strong>{{ $corte->cerrado_at?->format('d/m/Y h:i A') ?? '—' }}</strong></div>
        </div>
        <span style="padding:.2rem .7rem; border-radius:999px; font-size:.72rem; font-weight:700; text-transform:uppercase;
            background:{{ $cerrado ? 'rgba(107,114,128,.18)' : 'rgba(16,185,129,.15)' }};
            color:{{ $cerrado ? '#9ca3af' : '#10b981' }};">{{ $cerrado ? 'Cerrado' : 'Abierto' }}</span>
    </div>

    {{-- ───── Tarjetas de totales ───── --}}
    <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.5rem;">
        @php
            $cards = [
                ['Ventas', $cantidad.' · L. '.number_format($totalVentas, 2)],
                ['ISV del turno', 'L. '.number_format($totalIsv, 2)],
                ['Fondo inicial (efectivo)', 'L. '.number_format($fondo, 2)],
                ['Saldo inicial terminal POS', 'L. '.number_format((float) $corte->fondo_terminal, 2)],
                ['Nuevo saldo terminal POS', 'L. '.number_format($cerrado ? (float) $corte->terminal_final : (float) $corte->fondo_terminal + $tarjeta + $transfer, 2)],
                ['Efectivo esperado', 'L. '.number_format($esperado, 2)],
            ];
        @endphp
        @foreach ($cards as [$lbl, $val])
            <div style="background:rgba(128,128,128,.07); border:1px solid rgba(128,128,128,.18); border-radius:.6rem; padding:.55rem .65rem;">
                <div style="font-size:.7rem; opacity:.55; text-transform:uppercase; letter-spacing:.02em;">{{ $lbl }}</div>
                <div style="font-weight:800; font-size:1.02rem; margin-top:.15rem;">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- ───── Recibido por forma de pago ───── --}}
    <div style="border:1px solid rgba(128,128,128,.18); border-radius:.6rem; padding:.7rem .8rem;">
        <div style="font-weight:700; margin-bottom:.5rem;">Recibido por forma de pago</div>
        <div style="display:flex; flex-direction:column; gap:.3rem;">
            <div style="display:flex; justify-content:space-between;"><span style="opacity:.7;">Efectivo</span><strong>L. {{ number_format($efectivo, 2) }}</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="opacity:.7;">Tarjeta</span><strong>L. {{ number_format($tarjeta, 2) }}</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="opacity:.7;">Transferencia</span><strong>L. {{ number_format($transfer, 2) }}</strong></div>
        </div>
        @if (count($tarjetaBanco) > 0)
            <div style="border-top:1px dashed rgba(128,128,128,.25); margin-top:.5rem; padding-top:.5rem;">
                <div style="font-size:.72rem; opacity:.55; text-transform:uppercase; margin-bottom:.3rem;">Tarjeta por banco</div>
                <div style="display:flex; flex-direction:column; gap:.25rem;">
                    @foreach ($tarjetaBanco as $b)
                        <div style="display:flex; justify-content:space-between;"><span style="opacity:.8;">{{ $b->banco }}</span><strong>L. {{ number_format((float) $b->total, 2) }}</strong></div>
                    @endforeach
                </div>
            </div>
        @endif
        @if (count($porBanco) > 0 || $sinBanco > 0)
            <div style="border-top:1px dashed rgba(128,128,128,.25); margin-top:.5rem; padding-top:.5rem;">
                <div style="font-size:.72rem; opacity:.55; text-transform:uppercase; margin-bottom:.3rem;">Transferencias por banco</div>
                <div style="display:flex; flex-direction:column; gap:.25rem;">
                    @foreach ($porBanco as $b)
                        <div style="display:flex; justify-content:space-between;"><span style="opacity:.8;">{{ $b->banco }}</span><strong>L. {{ number_format((float) $b->total, 2) }}</strong></div>
                    @endforeach
                    @if ($sinBanco > 0)
                        <div style="display:flex; justify-content:space-between;"><span style="opacity:.8;">Sin banco</span><strong>L. {{ number_format($sinBanco, 2) }}</strong></div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- ───── Conteo de efectivo (al cerrar) ───── --}}
    @if ($cerrado)
        <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
            <div style="background:rgba(128,128,128,.07); border:1px solid rgba(128,128,128,.18); border-radius:.6rem; padding:.55rem .65rem;">
                <div style="font-size:.7rem; opacity:.55; text-transform:uppercase;">Efectivo esperado</div>
                <div style="font-weight:800; margin-top:.15rem;">L. {{ number_format($esperado, 2) }}</div>
            </div>
            <div style="background:rgba(128,128,128,.07); border:1px solid rgba(128,128,128,.18); border-radius:.6rem; padding:.55rem .65rem;">
                <div style="font-size:.7rem; opacity:.55; text-transform:uppercase;">Efectivo contado</div>
                <div style="font-weight:800; margin-top:.15rem;">L. {{ number_format($contado, 2) }}</div>
            </div>
            <div style="border:1px solid {{ $diferencia === 0.0 ? 'rgba(16,185,129,.4)' : 'rgba(239,68,68,.4)' }}; border-radius:.6rem; padding:.55rem .65rem;
                background:{{ $diferencia === 0.0 ? 'rgba(16,185,129,.08)' : 'rgba(239,68,68,.08)' }};">
                <div style="font-size:.7rem; opacity:.55; text-transform:uppercase;">Diferencia</div>
                <div style="font-weight:800; margin-top:.15rem; color:{{ $diferencia === 0.0 ? '#10b981' : '#ef4444' }};">L. {{ number_format($diferencia, 2) }}</div>
            </div>
        </div>
    @endif

    {{-- ───── Repartidores: lo que la empresa les debe por viajes ───── --}}
    @if ($domViajeTransfer > 0)
        <div style="border:1px solid rgba(245,158,11,.4); background:rgba(245,158,11,.07); border-radius:.6rem; padding:.7rem .8rem;">
            <div style="display:flex; justify-content:space-between; font-weight:700; color:#f59e0b;">
                <span>🛵 A pagar a repartidores (viajes a domicilio)</span><span>L. {{ number_format($domViajeTransfer, 2) }}</span>
            </div>
            <div style="font-size:.7rem; opacity:.55; margin-top:.35rem;">
                Domicilios pagados por transferencia: el dinero te llegó a vos, así que le pagás el viaje al repartidor. (Los pagados en efectivo ya los entrega el repartidor con el efectivo del turno.)
            </div>
        </div>
    @endif

    {{-- ───── Ventas del turno ───── --}}
    <div>
        <div style="font-weight:700; margin-bottom:.3rem;">Ventas del turno</div>
        <table style="width:100%; border-collapse:collapse; font-size:.82rem;">
            <thead>
                <tr style="text-align:left; opacity:.55; text-transform:uppercase; font-size:.7rem;">
                    <th style="padding:.3rem .2rem;">Hora</th>
                    <th style="padding:.3rem .2rem;">Documento</th>
                    <th style="padding:.3rem .2rem;">Pago</th>
                    <th style="padding:.3rem .2rem; text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
                {{-- Las anuladas se listan tachadas (transparencia para auditar) pero NO suman en los totales de arriba. --}}
                @forelse ($corte->ventas()->with('factura:id,venta_id,numero,anulada')->orderBy('vendida_at')->get() as $v)
                    @php $anulada = (bool) ($v->factura->anulada ?? false); @endphp
                    <tr style="border-top:1px solid rgba(128,128,128,.12); {{ $anulada ? 'opacity:.45;' : '' }}">
                        <td style="padding:.35rem .2rem; {{ $anulada ? 'text-decoration:line-through;' : '' }}">{{ $v->vendida_at->format('h:i A') }}</td>
                        <td style="padding:.35rem .2rem;">
                            @if ($v->factura !== null)
                                {{-- Clickeable: abre el visor de factura de arriba (subrayado punteado = se puede tocar). --}}
                                <span role="button" title="Ver factura {{ $v->factura->numero }}"
                                    @click="facturaUrl = '{{ $v->factura->urlTicket() }}'; facturaNumero = '{{ $v->factura->numero }}'"
                                    style="cursor:pointer; text-decoration:{{ $anulada ? 'line-through' : 'underline dotted' }}; text-underline-offset:3px;">{{ ucfirst($v->tipo) }}{{ $v->numero_recibo ? ' '.$v->numero_recibo : '' }}</span>
                            @else
                                <span style="{{ $anulada ? 'text-decoration:line-through;' : '' }}">{{ ucfirst($v->tipo) }}{{ $v->numero_recibo ? ' '.$v->numero_recibo : '' }}</span>
                            @endif
                            @if ($anulada)
                                <span style="margin-left:.35rem; padding:.05rem .45rem; border-radius:999px; font-size:.62rem; font-weight:700; text-transform:uppercase; background:rgba(239,68,68,.15); color:#ef4444;">Anulada</span>
                            @endif
                        </td>
                        <td style="padding:.35rem .2rem; {{ $anulada ? 'text-decoration:line-through;' : '' }}">{{ ucfirst($v->forma_pago) }}{{ $v->banco ? ' · '.$v->banco : '' }}</td>
                        <td style="padding:.35rem .2rem; text-align:right; font-weight:600; {{ $anulada ? 'text-decoration:line-through;' : '' }}">L. {{ number_format((float) $v->total, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:.6rem; opacity:.55; text-align:center;">Sin ventas en este turno.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
