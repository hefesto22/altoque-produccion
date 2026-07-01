<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- Fecha + servicio --}}
        <x-filament::section>
            <x-slot name="heading">Fecha y servicio</x-slot>
            <x-slot name="description">Elegí la fecha y el servicio, después marcá los productos que se venden.</x-slot>

            <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem;">
                <div>
                    <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Fecha</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="fecha" />
                    </x-filament::input.wrapper>
                </div>
                <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                    @foreach ($servicios as $s)
                        <x-filament::button
                            :color="$servicioId === $s['id'] ? 'primary' : 'gray'"
                            wire:click="cambiarServicio({{ $s['id'] }})">
                            {{ $s['nombre'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>
        </x-filament::section>

        {{-- Productos por categoría --}}
        @foreach (['proteina' => 'Proteínas', 'complemento' => 'Complementos', 'bebida' => 'Bebidas', 'extra' => 'Extras', 'combo' => 'Platillos completos'] as $cat => $titulo)
            @if (! empty($productosPorCategoria[$cat]))
                <x-filament::section>
                    <x-slot name="heading">{{ $titulo }}</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                        @foreach ($productosPorCategoria[$cat] as $p)
                            <label style="display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem; cursor:pointer;">
                                <input type="checkbox" wire:model="seleccionados" value="{{ $p['id'] }}" style="width:1.1rem; height:1.1rem;" />
                                <span>
                                    <span style="font-weight:600;">{{ $p['nombre'] }}</span>
                                    <span style="display:block; font-size:.72rem; opacity:.7;">L. {{ number_format((float) $p['precio'], 2) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @endforeach

        {{-- Combos a mostrar en la pantalla del menú --}}
        @if (! empty($combos))
            <x-filament::section>
                <x-slot name="heading">Combos en la pantalla</x-slot>
                <x-slot name="description">Marcá qué combos se anuncian en la pantalla del menú para este servicio. Si no marcás ninguno en todo el día, se muestran todos.</x-slot>
                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($combos as $c)
                        <label style="display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem; cursor:pointer;">
                            <input type="checkbox" wire:model="combosSeleccionados" value="{{ $c['id'] }}" style="width:1.1rem; height:1.1rem;" />
                            <span>
                                <span style="font-weight:600;">{{ $c['nombre'] }}</span>
                                <span style="display:block; font-size:.72rem; opacity:.7;">L. {{ number_format($c['precio'], 2) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
            <span style="font-size:.9rem; opacity:.8;">{{ count($seleccionados) }} producto(s) y {{ count($combosSeleccionados) }} combo(s) en <strong>{{ $this->nombreServicio() }}</strong></span>
            <x-filament::button wire:click="guardar" icon="heroicon-o-check" size="lg">Guardar menú del día</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
