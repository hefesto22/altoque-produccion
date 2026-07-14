{{--
    Lista de abonos registrados de una cotización (dentro del modal
    "Registrar abono"). Colores translúcidos: modo día y noche.
--}}
<div style="display:flex; flex-direction:column; gap:.25rem; margin-bottom:.5rem;">
    @if ($pagos->isEmpty())
        <div style="text-align:center; padding:.75rem; opacity:.6; font-size:.85rem;">
            Sin abonos todavía — este será el primero.
        </div>
    @else
        <div style="font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; opacity:.6; padding:.2rem .1rem;">
            Abonos registrados
        </div>
        @foreach ($pagos as $p)
            <div style="display:flex; align-items:center; gap:.75rem; padding:.4rem .1rem; border-bottom:1px solid rgba(128,128,128,.15); font-size:.85rem;">
                <div style="flex:1 1 auto;">
                    <span style="font-weight:600;">{{ $p->recibido_at->format('d/m/Y H:i') }}</span>
                    <span style="display:block; font-size:.72rem; opacity:.65;">
                        {{ ucfirst($p->forma_pago) }}@if ($p->banco) · {{ $p->banco }}@endif
                        @if ($p->receptor) · {{ $p->receptor->name }}@endif
                        @if ($p->notas) · {{ $p->notas }}@endif
                    </span>
                </div>
                <div style="font-weight:700;">L. {{ number_format((float) $p->monto, 2) }}</div>
            </div>
        @endforeach
    @endif
</div>
