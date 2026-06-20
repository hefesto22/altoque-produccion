<x-filament-panels::page>
    {{-- Auto-refresco cada 5s --}}
    <div wire:poll.5s style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- ───── Alertas de reposición ───── --}}
        @php($alertas = $this->alertas())
        @if (count($alertas))
            <x-filament::section>
                <x-slot name="heading">⚠ Reponer en el buffet</x-slot>
                <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                    @foreach ($alertas as $a)
                        <div style="display:flex; align-items:center; gap:.5rem; padding:.4rem .6rem; border:1px solid #f59e0b; border-radius:.5rem; background:rgba(245,158,11,.1);">
                            <span style="font-weight:700;">{{ $a->producto?->nombre }}</span>
                            <span style="font-size:.7rem; opacity:.7;">{{ $a->created_at->diffForHumans() }}</span>
                            <x-filament::button size="xs" color="success" wire:click="reponer({{ $a->producto_id }})">Repuesto</x-filament::button>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ───── Comandas ───── --}}
        @php($comandas = $this->comandas())
        <x-filament::section>
            <x-slot name="heading">Comandas en cocina ({{ count($comandas) }})</x-slot>

            @if (count($comandas) === 0)
                <p style="text-align:center; padding:2rem 0; opacity:.6;">No hay comandas pendientes.</p>
            @else
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:1rem;">
                    @foreach ($comandas as $c)
                        @php($borde = $c->estado === 'listo' ? '#10b981' : ($c->estado === 'preparando' ? '#f59e0b' : '#6b7280'))
                        <div style="border:2px solid {{ $borde }}; border-radius:.75rem; padding:.85rem; display:flex; flex-direction:column; gap:.5rem;">
                            <div style="display:flex; align-items:center; justify-content:space-between;">
                                <span style="font-weight:800; font-size:1.1rem;">{{ $c->numero }}</span>
                                <x-filament::badge :color="$c->tipo === 'domicilio' ? 'info' : 'gray'">
                                    {{ $c->tipo === 'domicilio' ? 'Domicilio' : 'Para llevar' }}
                                </x-filament::badge>
                            </div>

                            <div style="font-size:.72rem; opacity:.7;">{{ $c->created_at->diffForHumans() }}</div>

                            @if ($c->esDomicilio())
                                <div style="font-size:.78rem; background:rgba(59,130,246,.1); border-radius:.4rem; padding:.4rem;">
                                    <div><strong>{{ $c->cliente_nombre ?: 'Sin nombre' }}</strong></div>
                                    <div>📞 {{ $c->cliente_telefono }}</div>
                                    <div>📍 {{ $c->cliente_direccion }}</div>
                                    @if ($c->cliente_identidad)<div>ID: {{ $c->cliente_identidad }}</div>@endif
                                </div>
                            @endif

                            <ul style="margin:0; padding-left:1.1rem; font-size:.85rem;">
                                @foreach (($c->items ?? []) as $item)
                                    <li>
                                        <strong>{{ $item['cantidad'] }}×</strong> {{ $item['nombre'] }}
                                        @if (! empty($item['detalle']))
                                            <span style="display:block; font-size:.72rem; opacity:.7;">{{ implode(', ', $item['detalle']) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <div style="display:flex; gap:.4rem; margin-top:auto; flex-wrap:wrap;">
                                @if ($c->estado === 'pendiente')
                                    <x-filament::button size="xs" color="warning" wire:click="preparando({{ $c->id }})">Preparando</x-filament::button>
                                @endif
                                @if (in_array($c->estado, ['pendiente', 'preparando'], true))
                                    <x-filament::button size="xs" color="success" wire:click="listo({{ $c->id }})">Listo</x-filament::button>
                                @endif
                                @if ($c->estado === 'listo')
                                    <span style="font-weight:700; color:#10b981; align-self:center;">✓ LISTO</span>
                                    <x-filament::button size="xs" color="gray" wire:click="entregado({{ $c->id }})">Entregado</x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
