{{--
    Libro mayor de una cuenta prepago, dentro del modal "Movimientos".
    Se agrega y nunca se borra: es el respaldo de por qué el saldo es el que
    es. Colores translúcidos para que sirva en modo día y noche.
--}}
@php($saldo = (float) $cuenta->saldo)

<div style="display:flex; align-items:baseline; justify-content:space-between; gap:1rem; padding:.5rem .1rem .7rem; border-bottom:1px solid rgba(128,128,128,.2);">
    <div>
        <div style="font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; opacity:.6;">Saldo actual</div>
        <div style="font-size:1.4rem; font-weight:800; color:{{ $saldo < 0 ? '#dc2626' : '#16a34a' }};">
            L. {{ number_format($saldo, 2) }}
        </div>
    </div>
    <div style="text-align:right; font-size:.8rem; opacity:.75;">
        Depositado L. {{ number_format($cuenta->totalDepositado(), 2) }}<br>
        Consumido L. {{ number_format($cuenta->totalConsumido(), 2) }}
    </div>
</div>

<div style="display:flex; flex-direction:column; gap:.1rem; margin-top:.5rem; max-height:24rem; overflow-y:auto;">
    @forelse ($movimientos as $m)
        @php($monto = (float) $m->monto)
        <div style="display:flex; align-items:flex-start; gap:.75rem; padding:.45rem .1rem; border-bottom:1px solid rgba(128,128,128,.15); font-size:.85rem;">
            <div style="flex:1 1 auto; min-width:0;">
                <span style="font-weight:600;">{{ $m->tipoLabel() }}</span>
                <span style="opacity:.6;"> · {{ $m->created_at->format('d/m/Y h:i A') }}</span>
                <span style="display:block; font-size:.72rem; opacity:.65; word-break:break-word;">
                    @if ($m->formaLabel()){{ $m->formaLabel() }}@endif
                    @if ($m->venta?->factura?->numero) · Factura {{ $m->venta->factura->numero }}@endif
                    @if ($m->registradoPor) · {{ $m->registradoPor->name }}@endif
                    @if ($m->notas) · {{ $m->notas }}@endif
                </span>
            </div>
            <div style="text-align:right; white-space:nowrap;">
                <div style="font-weight:700; color:{{ $monto >= 0 ? '#16a34a' : 'inherit' }};">
                    {{ $monto >= 0 ? '+' : '−' }} L. {{ number_format(abs($monto), 2) }}
                </div>
                <div style="font-size:.7rem; opacity:.55;">Saldo: L. {{ number_format((float) $m->saldo_despues, 2) }}</div>
            </div>
        </div>
    @empty
        <div style="text-align:center; padding:1rem; opacity:.6; font-size:.85rem;">
            Esta cuenta todavía no tiene movimientos.
        </div>
    @endforelse
</div>
