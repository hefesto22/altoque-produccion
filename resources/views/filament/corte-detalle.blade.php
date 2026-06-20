<div style="display:flex; flex-direction:column; gap:.75rem; font-size:.9rem;">
    {{-- Resumen del turno --}}
    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.4rem;">
        <div><span style="opacity:.6;">Cajero:</span> <strong>{{ $corte->cajero?->name ?? '—' }}</strong></div>
        <div><span style="opacity:.6;">Estado:</span> <strong>{{ ucfirst($corte->estado) }}</strong></div>
        <div><span style="opacity:.6;">Abierto:</span> {{ $corte->abierto_at?->format('d/m/Y h:i A') }}</div>
        <div><span style="opacity:.6;">Cerrado:</span> {{ $corte->cerrado_at?->format('d/m/Y h:i A') ?? '—' }}</div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.3rem; background:rgba(128,128,128,.08); border-radius:.5rem; padding:.6rem;">
        <div><span style="opacity:.6;">Fondo inicial:</span> L. {{ number_format((float) $corte->fondo_inicial, 2) }}</div>
        <div><span style="opacity:.6;">Ventas:</span> {{ $corte->cantidad_ventas }} · L. {{ number_format((float) $corte->total_ventas, 2) }}</div>
        <div><span style="opacity:.6;">Efectivo:</span> L. {{ number_format((float) $corte->total_efectivo, 2) }}</div>
        <div><span style="opacity:.6;">Tarjeta:</span> L. {{ number_format((float) $corte->total_tarjeta, 2) }}</div>
        <div><span style="opacity:.6;">Transferencia:</span> L. {{ number_format((float) $corte->total_transferencia, 2) }}</div>
        <div><span style="opacity:.6;">ISV del turno:</span> L. {{ number_format((float) $corte->total_isv, 2) }}</div>
        <div><span style="opacity:.6;">Efectivo esperado:</span> <strong>L. {{ number_format($corte->efectivoEsperado(), 2) }}</strong></div>
        <div><span style="opacity:.6;">Efectivo contado:</span> <strong>L. {{ number_format((float) $corte->efectivo_contado, 2) }}</strong></div>
        <div style="grid-column:1/-1;">
            <span style="opacity:.6;">Diferencia:</span>
            <strong style="color:{{ (float) $corte->diferencia === 0.0 ? '#10b981' : '#ef4444' }};">L. {{ number_format((float) $corte->diferencia, 2) }}</strong>
        </div>
    </div>

    {{-- Ventas del turno --}}
    <div>
        <strong>Ventas del turno</strong>
        <table style="width:100%; border-collapse:collapse; font-size:.82rem; margin-top:.3rem;">
            <tr style="text-align:left; opacity:.7;">
                <th style="padding:.2rem;">Hora</th>
                <th style="padding:.2rem;">Tipo</th>
                <th style="padding:.2rem;">Pago</th>
                <th style="padding:.2rem; text-align:right;">Total</th>
            </tr>
            @forelse ($corte->ventas()->orderBy('vendida_at')->get() as $v)
                <tr style="border-top:1px solid rgba(128,128,128,.15);">
                    <td style="padding:.2rem;">{{ $v->vendida_at->format('h:i A') }}</td>
                    <td style="padding:.2rem;">{{ ucfirst($v->tipo) }}{{ $v->numero_recibo ? ' '.$v->numero_recibo : '' }}</td>
                    <td style="padding:.2rem;">{{ ucfirst($v->forma_pago) }}{{ $v->banco ? ' · '.$v->banco : '' }}</td>
                    <td style="padding:.2rem; text-align:right;">L. {{ number_format((float) $v->total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="padding:.5rem; opacity:.6; text-align:center;">Sin ventas en este turno.</td></tr>
            @endforelse
        </table>
    </div>
</div>
