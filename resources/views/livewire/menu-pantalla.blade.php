{{-- wire:poll: la pantalla se refresca sola cada 15s — al guardar el Menú del Día
     en el admin, la TV lo refleja sin tocar nada (es otro dispositivo, así que un
     evento Livewire no la alcanzaría; el poll es el mismo patrón del listado POS). --}}
<div wire:poll.10s
    x-data="{
        locked: localStorage.getItem('menu_locked') === '1',
        toggle() { this.locked = !this.locked; localStorage.setItem('menu_locked', this.locked ? '1' : '0'); }
    }"
    x-init="$nextTick(() => { let s = localStorage.getItem('menu_servicio'); if (s) $wire.setServicio(parseInt(s)); })">

    {{-- Barra de control (oculta cuando está bloqueada) --}}
    <div class="bar" x-show="!locked" x-cloak>
        <span style="font-weight:800;">{{ $empresa['nombre'] }}</span>
        @foreach ($servicios as $s)
            <button class="btn {{ $servicioId === $s['id'] ? 'on' : '' }}"
                wire:click="setServicio({{ $s['id'] }})"
                x-on:click="localStorage.setItem('menu_servicio', {{ $s['id'] }})">
                {{ $s['nombre'] }}
            </button>
        @endforeach
        <span class="sp"></span>
        <button class="btn lock" x-on:click="toggle()">🔒 Bloquear pantalla</button>
    </div>

    {{-- Botón discreto para desbloquear --}}
    <button class="unlock" x-show="locked" x-cloak x-on:click="toggle()" title="Desbloquear">🔓</button>

    {{-- Menú formato flyer, vertical para pantalla tótem (0.70 x 1.90) --}}
    <div class="board" :class="locked ? '' : 'pushed'">
        <div class="head {{ $bebidas->isNotEmpty() ? 'sin-linea' : '' }}">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="logo">
            @endif
            <div class="titulo">{{ $empresa['nombre'] }}</div>
            <div class="sub">🟡 Menú {{ ucfirst($fecha) }}</div>
        </div>

        {{-- Cinta de bebidas en movimiento (marquee). La velocidad escala con la
             cantidad de bebidas para que se lea igual con 5 que con 40. wire:ignore
             NO hace falta: si el menú no cambió, Livewire no toca el DOM y la
             animación sigue corriendo entre polls. --}}
        @if ($bebidas->isNotEmpty())
            <div class="cinta">
                <div class="cinta-track" style="animation-duration: {{ max(18, $bebidas->count() * 3) }}s;">
                    @foreach ([0, 1] as $copia)
                        <span class="cinta-grupo">
                            <span class="cinta-item">🥤 BEBIDAS</span>
                            <span class="cinta-sep">●</span>
                            @foreach ($bebidas as $b)
                                <span class="cinta-item">{{ mb_strtoupper($b->nombre) }}<span class="precio">L.{{ number_format((float) $b->precio, 0) }}</span></span>
                                <span class="cinta-sep">●</span>
                            @endforeach
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Proteínas del día --}}
        @forelse ($proteinas as $p)
            <div class="item"><span class="ck">✔</span><span>{{ $p->nombre }}</span></div>
        @empty
            <div class="item"><span>No hay menú cargado para este servicio.</span></div>
        @endforelse

        {{-- Complementos --}}
        @if ($complementos->isNotEmpty())
            <div class="seccion">📒 COMPLEMENTOS</div>
            @foreach ($complementos as $c)
                <div class="item"><span class="ck">✔</span><span>{{ $c->nombre }}</span></div>
            @endforeach
        @endif

        {{-- Precios individuales --}}
        @if (count($individuales))
            <div class="precios">⚜️ INDIVIDUALES: {{ implode(', ', $individuales) }}</div>
        @endif

        {{-- Combos --}}
        @if (count($combos))
            <div class="combo-h">🟢 PRECIOS EN DESCUENTO</div>
            @foreach ($combos as $combo)
                <div class="combo">{{ $combo }}</div>
            @endforeach
        @endif

        {{-- Platillos completos (con nombre y precio fijo) --}}
        @if ($combosEspeciales->isNotEmpty())
            <div class="combo-h">⭐ PLATILLOS COMPLETOS</div>
            @foreach ($combosEspeciales as $ce)
                <div class="combo">
                    {{ mb_strtoupper($ce['nombre']) }} L.{{ number_format($ce['precio'], 2) }}
                    @if ($ce['desglose'])<span style="display:block; font-size:2.6vw; font-weight:500; opacity:.75;">{{ $ce['desglose'] }}</span>@endif
                </div>
            @endforeach
        @endif

        {{-- Contacto al pie --}}

        <div class="pie-wrap">
            @if ($empresa['telefono'])<div class="pie">📲 {{ $empresa['telefono'] }}</div>@endif
            @if ($empresa['formas_pago_texto'])<div class="pie">💳 {{ $empresa['formas_pago_texto'] }}</div>@endif
            @if ($empresa['direccion'])<div class="pie">📍 {{ $empresa['direccion'] }}</div>@endif
            @if ($empresa['horario'])<div class="pie">🕐 {{ $empresa['horario'] }}</div>@endif
        </div>
    </div>
</div>
