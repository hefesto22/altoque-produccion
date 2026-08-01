<div>
    @if ($visible)
        {{-- Franja fija arriba del panel. Colores propios (no los de la marca):
             esto es un aviso de cobro, tiene que destacar sobre el resto. --}}
        <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
                    margin:0 0 1rem; padding:.85rem 1.1rem;
                    border:1px solid #f59e0b; border-left:5px solid #f59e0b; border-radius:.6rem;
                    background:rgba(245,158,11,.10);">

            <div style="flex:1; min-width:18rem; line-height:1.5;">
                <div style="font-weight:800; font-size:.95rem;">
                    ⏰ Pago del sistema — {{ $mes }}
                    <span style="font-weight:700;">L. {{ number_format((float) $monto, 2) }}</span>
                    @if ($concepto)
                        <span style="opacity:.7; font-weight:600; font-size:.82rem;">({{ $concepto }})</span>
                    @endif
                </div>
                <div style="font-size:.85rem; opacity:.85;">
                    {{ config('cobro.banco') }} · Cuenta
                    <strong style="letter-spacing:.03em;">{{ config('cobro.cuenta') }}</strong>
                </div>
                <div style="font-size:.85rem; opacity:.85;">
                    A nombre de <strong>{{ config('cobro.titular') }}</strong>
                </div>
            </div>

            <x-filament::button color="warning" wire:click="marcarRecibido" wire:loading.attr="disabled">
                Recordatorio recibido
            </x-filament::button>
        </div>
    @endif
</div>
