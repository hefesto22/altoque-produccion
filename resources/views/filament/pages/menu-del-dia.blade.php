<x-filament-panels::page>
    {{--
        Filtro de productos 100% en el navegador (Alpine): con el catálogo
        completo renderizado, buscar no pega al servidor. normalizar() quita
        tildes para que "pure" encuentre "Puré de papas".
    --}}
    <div
        style="display:flex; flex-direction:column; gap:1.5rem;"
        x-data="{
            filtro: '',
            normalizar(s) {
                return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            },
            coincide(nombre) {
                return this.filtro === '' || this.normalizar(nombre).includes(this.normalizar(this.filtro));
            },
        }"
    >

        {{-- Fecha + servicio --}}
        <x-filament::section>
            <x-slot name="heading">Fecha y servicio</x-slot>
            <x-slot name="description">Elegí la fecha y el servicio, después marcá los productos que se venden. Podés dejar armado el menú de mañana o de cualquier día.</x-slot>

            <div style="display:flex; flex-direction:column; gap:.75rem;">
                <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem;">
                    <div>
                        <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Fecha</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="fecha" />
                        </x-filament::input.wrapper>
                    </div>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                        <x-filament::button color="gray" size="sm" wire:click="irAFecha(0)">Hoy</x-filament::button>
                        <x-filament::button color="gray" size="sm" wire:click="irAFecha(1)">Mañana</x-filament::button>
                        <x-filament::button color="gray" size="sm" wire:click="irAFecha(2)">Pasado mañana</x-filament::button>
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

                {{-- Qué día se está editando (resaltado si no es hoy) --}}
                <div style="display:flex; align-items:center; gap:.5rem; padding:.5rem .75rem; border-radius:.5rem; font-size:.9rem;
                            {{ $this->esOtroDia()
                                ? 'background:rgba(217,119,6,.12); border:1px solid rgba(217,119,6,.45);'
                                : 'background:rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.3);' }}">
                    <x-filament::icon icon="{{ $this->esOtroDia() ? 'heroicon-o-clock' : 'heroicon-o-calendar-days' }}" style="width:1.1rem; height:1.1rem;" />
                    <span>Editando el menú del <strong>{{ $this->etiquetaFecha() }}</strong> · {{ $this->nombreServicio() }}</span>
                </div>

                {{-- Buscador --}}
                <div>
                    <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Buscar producto</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" style="max-width:24rem;">
                        <x-filament::input type="search" x-model="filtro" placeholder="Ej: pollo, fresco, puré…" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        {{-- Plato especial del día: se crea con el botón del encabezado --}}
        <x-filament::section>
            <x-slot name="heading">🍽️ Plato del día</x-slot>
            <x-slot name="description">El especial que la cocina hace solo este día. Se publica en el POS y en la pantalla del menú en los tres servicios de la fecha, y no aparece ningún otro día.</x-slot>

            @if (empty($platosDelDia))
                <div style="padding:.9rem 1rem; border:1px dashed rgba(128,128,128,.35); border-radius:.6rem; font-size:.88rem; opacity:.75;">
                    Todavía no hay plato del día para <strong>{{ $this->etiquetaFecha() }}</strong>. Usá el botón <strong>Crear plato del día</strong> de arriba.
                </div>
            @else
                <div style="display:flex; flex-direction:column; gap:.5rem;">
                    @foreach ($platosDelDia as $plato)
                        <div style="display:flex; align-items:flex-start; gap:1rem; padding:.7rem .9rem; border:1px solid rgba(217,119,6,.45); background:rgba(217,119,6,.08); border-radius:.6rem;">
                            <div style="flex:1; min-width:0;">
                                <div style="font-weight:700;">
                                    {{ $plato['nombre'] }}
                                    <span style="font-weight:500; opacity:.75;">· L. {{ number_format($plato['precio'], 2) }}</span>
                                </div>
                                @if ($plato['desglose'])
                                    <div style="font-size:.78rem; opacity:.8; margin-top:.15rem;">Lleva: {{ $plato['desglose'] }}</div>
                                @endif
                                @if ($plato['nota'])
                                    <div style="font-size:.72rem; opacity:.6; margin-top:.15rem;">{{ $plato['nota'] }}</div>
                                @endif
                            </div>

                            <x-filament::button
                                size="xs" color="danger" outlined icon="heroicon-o-trash"
                                wire:click="eliminarPlatoDelDia({{ $plato['id'] }})"
                                wire:confirm="¿Quitar «{{ $plato['nombre'] }}» del menú de este día?"
                            >
                                Quitar
                            </x-filament::button>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Productos por categoría --}}
        @foreach (['proteina' => 'Proteínas', 'complemento' => 'Complementos', 'bebida' => 'Bebidas', 'extra' => 'Extras', 'combo' => 'Platillos completos'] as $cat => $titulo)
            @if (! empty($productosPorCategoria[$cat]))
                {{-- La sección se oculta si ningún producto coincide con el filtro --}}
                <x-filament::section x-show="{{ json_encode(array_map(static fn ($p) => $p['nombre'], $productosPorCategoria[$cat])) }}.some(n => coincide(n))">
                    <x-slot name="heading">{{ $titulo }}</x-slot>

                    {{-- Marcar / desmarcar toda la categoría de un clic (ej. bebidas: casi siempre están todas) --}}
                    <x-slot name="afterHeader">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            icon="{{ $this->categoriaCompleta($cat) ? 'heroicon-o-minus-circle' : 'heroicon-o-check-circle' }}"
                            wire:click="alternarCategoria('{{ $cat }}')"
                        >
                            {{ $this->categoriaCompleta($cat) ? 'Quitar todo' : 'Seleccionar todo' }}
                        </x-filament::button>
                    </x-slot>

                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                        @foreach ($productosPorCategoria[$cat] as $p)
                            <label
                                x-show="coincide(@js($p['nombre']))"
                                style="display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem; cursor:pointer;">
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
            <x-filament::section x-show="{{ json_encode(array_map(static fn ($c) => $c['nombre'], $combos)) }}.some(n => coincide(n))">
                <x-slot name="heading">Combos en la pantalla</x-slot>
                <x-slot name="description">Marcá qué combos se anuncian en la pantalla del menú para este servicio. Si no marcás ninguno en todo el día, se muestran todos.</x-slot>

                {{-- Marcar / desmarcar todos los combos de un clic --}}
                <x-slot name="afterHeader">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="{{ $this->combosCompletos() ? 'heroicon-o-minus-circle' : 'heroicon-o-check-circle' }}"
                        wire:click="alternarCombos"
                    >
                        {{ $this->combosCompletos() ? 'Quitar todo' : 'Seleccionar todo' }}
                    </x-filament::button>
                </x-slot>

                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($combos as $c)
                        <label
                            x-show="coincide(@js($c['nombre']))"
                            style="display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem; cursor:pointer;">
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
            <span style="font-size:.9rem; opacity:.8;">{{ count($seleccionados) }} producto(s) y {{ count($combosSeleccionados) }} combo(s) en <strong>{{ $this->nombreServicio() }}</strong> · {{ $this->etiquetaFecha() }}</span>
            <x-filament::button wire:click="guardar" icon="heroicon-o-check" size="lg">Guardar menú del día</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
