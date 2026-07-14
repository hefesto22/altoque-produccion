{{--
    Historial de compras de un cliente (modal lateral en Clientes).
    Los totales del resumen cubren TODO el historial (calculados en SQL,
    sin contar anuladas); la lista muestra las últimas facturas agrupadas
    por mes con su subtotal. Solo colores translúcidos: se ve bien en
    modo día y modo noche sin estilos por tema.
--}}
<div style="display:flex; flex-direction:column; gap:1rem;">

    {{-- Resumen --}}
    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
        <div style="padding:.6rem .75rem; border:1px solid rgba(128,128,128,.25); border-radius:.6rem;">
            <div style="font-size:.72rem; opacity:.6;">Compras</div>
            <div style="font-size:1.15rem; font-weight:800;">{{ number_format($compras) }}</div>
        </div>
        <div style="padding:.6rem .75rem; border:1px solid rgba(34,197,94,.4); border-radius:.6rem; background:rgba(34,197,94,.07);">
            <div style="font-size:.72rem; opacity:.6;">Total comprado</div>
            <div style="font-size:1.15rem; font-weight:800; color:#16a34a;">L. {{ number_format($total, 2) }}</div>
        </div>
        <div style="padding:.6rem .75rem; border:1px solid rgba(128,128,128,.25); border-radius:.6rem;">
            <div style="font-size:.72rem; opacity:.6;">Última compra</div>
            <div style="font-size:1.15rem; font-weight:800;">{{ $ultima?->format('d/m/Y') ?? '—' }}</div>
        </div>
    </div>

    @if ($facturas->isEmpty())
        <div style="text-align:center; padding:2.5rem 1rem; opacity:.6;">
            <div style="font-size:2rem;">🧾</div>
            Este cliente todavía no tiene compras facturadas.
        </div>
    @else
        {{-- Facturas agrupadas por mes, de la más reciente a la más vieja --}}
        @foreach ($facturas->groupBy(fn ($f) => $f->emitida_at->format('Y-m')) as $grupo)
            @php($subtotal = $grupo->where('anulada', false)->sum('total'))
            @php($mes = $grupo->first()->emitida_at->copy()->locale('es'))

            <div>
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:.5rem; padding:.35rem .2rem; border-bottom:2px solid rgba(128,128,128,.3); margin-bottom:.35rem;">
                    <span style="font-weight:800; font-size:.82rem; text-transform:uppercase; letter-spacing:.03em;">{{ $mes->isoFormat('MMMM YYYY') }}</span>
                    <span style="font-size:.82rem; font-weight:700; opacity:.85;">L. {{ number_format((float) $subtotal, 2) }}</span>
                </div>

                @foreach ($grupo as $f)
                    <div style="display:flex; align-items:center; gap:.75rem; padding:.45rem .2rem; border-bottom:1px solid rgba(128,128,128,.14); {{ $f->anulada ? 'opacity:.55;' : '' }}">
                        <div style="flex:1 1 auto; min-width:0;">
                            <div style="font-weight:600; font-size:.86rem;">{{ $f->emitida_at->format('d/m/Y H:i') }}</div>
                            <div style="font-size:.72rem; opacity:.65;">Factura {{ $f->numero }}</div>
                        </div>
                        <div style="font-size:.74rem; opacity:.7; text-transform:capitalize;">{{ $f->forma_pago ?? '' }}</div>
                        @if ($f->anulada)
                            <span style="font-size:.66rem; font-weight:800; color:#dc2626; border:1px solid rgba(220,38,38,.5); border-radius:.35rem; padding:.1rem .35rem;">ANULADA</span>
                        @endif
                        <div style="font-weight:700; min-width:6.5rem; text-align:right; {{ $f->anulada ? 'text-decoration:line-through;' : '' }}">
                            L. {{ number_format((float) $f->total, 2) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        @if ($truncado)
            <div style="font-size:.78rem; opacity:.6; text-align:center;">
                Se muestran las últimas {{ $facturas->count() }} facturas. Los totales del resumen sí incluyen todo el historial.
            </div>
        @endif
    @endif
</div>
