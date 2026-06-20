<x-filament-panels::page>
    <div wire:poll.10s>
        @php($pedidos = $this->pendientes())

        @if (count($pedidos) === 0)
            <x-filament::section>
                <p style="text-align:center; padding:2.5rem 0; opacity:.6;">No hay pedidos online pendientes.</p>
            </x-filament::section>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:1rem;">
                @foreach ($pedidos as $p)
                    <div style="border:2px solid #f59e0b; border-radius:.85rem; padding:1rem; display:flex; flex-direction:column; gap:.6rem; background:rgba(245,158,11,.05);">
                        {{-- Encabezado --}}
                        <div style="display:flex; align-items:center; justify-content:space-between;">
                            <span style="font-weight:800; font-size:1.15rem;">{{ $p->numero }}</span>
                            <x-filament::badge :color="$p->esDomicilio() ? 'info' : 'gray'">
                                {{ $p->esDomicilio() ? '🛵 Domicilio' : '🏃 Retiro' }}
                            </x-filament::badge>
                        </div>
                        <div style="font-size:.72rem; opacity:.7;">{{ $p->created_at->diffForHumans() }}</div>

                        {{-- Cliente --}}
                        <div style="font-size:.85rem; background:rgba(128,128,128,.1); border-radius:.5rem; padding:.5rem;">
                            <div><strong>{{ $p->cliente_nombre }}</strong></div>
                            <div>📞 {{ $p->cliente_telefono }}</div>
                            @if ($p->esDomicilio())<div>📍 {{ $p->cliente_direccion }}</div>@endif
                            @if ($p->cliente_identidad)<div style="opacity:.7;">ID: {{ $p->cliente_identidad }}</div>@endif
                            @if ($p->notas)<div style="margin-top:.25rem; font-style:italic;">“{{ $p->notas }}”</div>@endif
                        </div>

                        {{-- Items --}}
                        <ul style="margin:0; padding-left:1.1rem; font-size:.85rem;">
                            @foreach ($p->items as $item)
                                <li>
                                    <strong>{{ $item['cantidad'] }}×</strong> {{ $item['nombre'] }}
                                    @if (! empty($item['detalle']))
                                        <span style="display:block; font-size:.72rem; opacity:.7;">{{ implode(', ', $item['detalle']) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        {{-- Pago + total --}}
                        <div style="display:flex; align-items:center; justify-content:space-between; border-top:1px solid rgba(128,128,128,.2); padding-top:.5rem;">
                            <span style="font-size:.8rem;">
                                Pago: <strong>{{ ucfirst($p->metodo_pago) }}</strong>
                                @if ($p->metodo_pago === 'transferencia' && $p->comprobante_path)
                                    · <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($p->comprobante_path) }}" target="_blank" style="color:#3b82f6;">ver comprobante</a>
                                @endif
                            </span>
                            <span style="font-weight:800; font-size:1.1rem;">L. {{ number_format((float) $p->total, 2) }}</span>
                        </div>

                        {{-- Acciones --}}
                        <div style="display:flex; gap:.5rem; margin-top:.25rem;">
                            <x-filament::button color="success" style="flex:1;"
                                wire:click="confirmar({{ $p->id }})"
                                wire:confirm="¿Confirmar este pedido? Se enviará a cocina.">
                                ✓ Confirmar
                            </x-filament::button>
                            <x-filament::button color="danger" outlined
                                x-on:click="$wire.rechazar({{ $p->id }}, (prompt('Motivo del rechazo:') || ''))">
                                Rechazar
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
