{{--
    Historial de compras paginado (25 por página, consulta indexada).
    Solo colores translúcidos: se ve bien en modo día y modo noche.
--}}
<div style="display:flex; flex-direction:column; gap:1rem;">

    {{-- Resumen de TODO el historial (calculado una vez, en SQL) --}}
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
            <div style="font-size:1.15rem; font-weight:800;">{{ $ultima ?? '—' }}</div>
        </div>
    </div>

    @if ($totalRegistros === 0)
        <div style="text-align:center; padding:2.5rem 1rem; opacity:.6;">
            <div style="font-size:2rem;">🧾</div>
            Este cliente todavía no tiene compras facturadas.
        </div>
    @else
        {{-- Facturas de la página, agrupadas por mes (subtotal = total REAL del mes) --}}
        <div wire:loading.class="opacity-50" style="display:flex; flex-direction:column; gap:1rem;">
            @foreach ($facturas->groupBy(fn ($f) => $f->emitida_at->format('Y-m')) as $mesKey => $grupo)
                @php($mes = $grupo->first()->emitida_at->copy()->locale('es'))

                <div>
                    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:.5rem; padding:.35rem .2rem; border-bottom:2px solid rgba(128,128,128,.3); margin-bottom:.35rem;">
                        <span style="font-weight:800; font-size:.82rem; text-transform:uppercase; letter-spacing:.03em;">{{ $mes->isoFormat('MMMM YYYY') }}</span>
                        <span style="font-size:.82rem; font-weight:700; opacity:.85;" title="Total del mes completo">L. {{ number_format((float) $totalesMes->get($mesKey, 0), 2) }}</span>
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
        </div>

        {{-- Paginación: solo se consultan 25 facturas por página --}}
        @if ($paginas > 1)
            <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem; padding-top:.25rem;">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left"
                    wire:click="anterior" :disabled="$pagina <= 1">
                    Anterior
                </x-filament::button>

                <span style="font-size:.78rem; opacity:.7;">
                    Página {{ $pagina }} de {{ $paginas }} · {{ number_format($totalRegistros) }} facturas
                </span>

                <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after"
                    wire:click="siguiente" :disabled="$pagina >= $paginas">
                    Siguiente
                </x-filament::button>
            </div>
        @endif
    @endif
</div>
