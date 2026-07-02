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
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; text-transform:uppercase;">
                                <div>
                                    <span style="font-weight:900; font-size:1.5rem;">{{ $c->venta?->numero_orden ?? $c->numero }}</span>
                                    <div style="font-size:.65rem; opacity:.6;">{{ $c->numero }}</div>
                                </div>
                                <x-filament::badge :color="match ($c->tipo) { 'domicilio' => 'info', 'local' => 'warning', default => 'gray' }">
                                    {{ $c->tipoLabel() }}
                                </x-filament::badge>
                            </div>

                            <div style="font-size:.72rem; opacity:.7;">{{ $c->created_at->diffForHumans() }}</div>

                            @if ($c->esDomicilio())
                                <div style="font-size:.78rem; background:rgba(59,130,246,.1); border-radius:.4rem; padding:.4rem;">
                                    <div><strong>{{ $c->cliente_nombre ?: 'Sin nombre' }}</strong></div>
                                    <div>📞 {{ $c->cliente_telefono }}</div>
                                    <div>📍 {{ $c->cliente_direccion }}</div>
                                    @if ($c->cliente_identidad)<div>ID: {{ $c->cliente_identidad }}</div>@endif
                                    @if ($c->venta && (float) $c->venta->costo_viaje > 0)
                                        <div>🛵 Viaje: L. {{ number_format((float) $c->venta->costo_viaje, 2) }}</div>
                                    @endif
                                </div>
                            @elseif ($c->cliente_nombre)
                                <div style="font-size:.78rem; opacity:.85;">Para: <strong>{{ $c->cliente_nombre }}</strong></div>
                            @endif

                            {{-- Cambiar tipo de entrega (llevar ↔ domicilio): solo mientras
                                 se prepara; una vez LISTO el tipo ya no se cambia. --}}
                            @if (in_array($c->estado, ['pendiente', 'preparando'], true))
                            @if ($comandaADomicilio === $c->id)
                                <div style="background:rgba(59,130,246,.1); border:1px solid #3b82f6; border-radius:.5rem; padding:.5rem; display:flex; flex-direction:column; gap:.4rem;">
                                    <span style="font-size:.74rem; font-weight:700;">Datos para domicilio</span>
                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="entregaNombre" placeholder="Nombre" /></x-filament::input.wrapper>
                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="entregaTelefono" placeholder="Teléfono *" /></x-filament::input.wrapper>
                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="entregaDireccion" placeholder="Dirección *" /></x-filament::input.wrapper>
                                    <x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="entregaCostoViaje" placeholder="Costo del viaje (interno)" /></x-filament::input.wrapper>
                                    <div style="display:flex; gap:.4rem;">
                                        <x-filament::button size="xs" color="info" wire:click="confirmarDomicilio">Confirmar</x-filament::button>
                                        <x-filament::button size="xs" color="gray" wire:click="cancelarDomicilio">Cancelar</x-filament::button>
                                    </div>
                                </div>
                            @else
                                <div>
                                    @if ($c->tipo === 'domicilio')
                                        <button type="button" wire:click="pasarALlevar({{ $c->id }})"
                                            style="font-size:.7rem; padding:.2rem .5rem; border-radius:.35rem; border:1px solid rgba(128,128,128,.45); background:transparent; color:inherit; cursor:pointer;">→ Para llevar</button>
                                    @else
                                        <button type="button" wire:click="pedirDomicilio({{ $c->id }})"
                                            style="font-size:.7rem; padding:.2rem .5rem; border-radius:.35rem; border:1px solid #3b82f6; background:rgba(59,130,246,.12); color:inherit; cursor:pointer;">→ A domicilio</button>
                                    @endif
                                </div>
                            @endif
                            @endif

                            <ul style="margin:0; padding-left:1.1rem; font-size:.85rem;">
                                @foreach (($c->items ?? []) as $item)
                                    <li>
                                        <strong>{{ $item['cantidad'] }}×</strong> {{ $item['nombre'] }}
                                        @if (! empty($item['detalle']))
                                            <span style="display:block; font-size:.72rem; opacity:.7;">{{ implode(', ', $item['detalle']) }}</span>
                                        @endif
                                        @if (! empty($item['nota']))
                                            <span style="display:block; font-size:.74rem; color:#f59e0b; font-weight:700;">📝 {{ $item['nota'] }}</span>
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
