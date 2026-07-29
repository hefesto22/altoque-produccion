{{-- wire:poll: la pantalla se refresca sola cada 15s — al guardar el Menú del Día
     en el admin, la TV lo refleja sin tocar nada (es otro dispositivo, así que un
     evento Livewire no la alcanzaría; el poll es el mismo patrón del listado POS). --}}
{{-- El bloqueo se maneja con una clase en <html> (ver el layout), no con
     x-show: Livewire re-agregaba x-cloak en cada refresco y la barra y el
     candado parpadeaban. --}}
<div wire:poll.10s
    x-data="{
        toggle() {
            const bloqueada = ! document.documentElement.classList.contains('pantalla-bloqueada');
            document.documentElement.classList.toggle('pantalla-bloqueada', bloqueada);
            localStorage.setItem('menu_locked', bloqueada ? '1' : '0');
            window.dispatchEvent(new Event('resize')); // reajustar: cambió el alto útil
        }
    }"
    x-init="$nextTick(() => { let s = localStorage.getItem('menu_servicio'); if (s) $wire.setServicio(parseInt(s)); })">

    {{-- Barra de control (la oculta el CSS cuando la pantalla está bloqueada) --}}
    <div class="bar">
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

    {{-- Aviso de que el menú sigue más abajo (solo si no entra completo).
         Lo muestra/oculta el script de ajuste del layout; al tocarlo, baja. --}}
    <button type="button" class="mas-abajo"
        onclick="window.scrollBy({ top: window.innerHeight * 0.8, behavior: 'smooth' })">
        Hay más menú ⌄
    </button>

    {{-- Botón discreto para desbloquear --}}
    <button class="unlock" x-on:click="toggle()" title="Desbloquear">🔓</button>

    {{-- Menú formato flyer, vertical para pantalla tótem (0.70 x 1.90) --}}
    <div class="board">
        <div class="head {{ $bebidas->isNotEmpty() ? 'sin-linea' : '' }}">
            @if ($logoUrl)
                {{-- Dos caras para que el giro de moneda nunca muestre el logo en espejo --}}
                <div class="moneda">
                    <img src="{{ $logoUrl }}" alt="logo">
                    <img src="{{ $logoUrl }}" alt="" class="reverso" aria-hidden="true">
                </div>
            @endif
            <div class="titulo">{{ $empresa['nombre'] }}</div>
            <div class="sub">🟡 Menú {{ ucfirst($fecha) }}@if ($servicioNombre)<span class="servicio">{{ mb_strtoupper($servicioNombre) }}</span>@endif</div>
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
            <div class="item"><span>No hay menú cargado para {{ $servicioNombre ? mb_strtoupper($servicioNombre) : 'este servicio' }}.</span></div>
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
        @php($platosDelDia = $combosEspeciales->where('del_dia', true))
        @php($platillos = $combosEspeciales->where('del_dia', false))

        @if ($platosDelDia->isNotEmpty())
            <div class="combo-h">🍽️ PLATO DEL DÍA</div>
            @foreach ($platosDelDia as $ce)
                <div class="combo">
                    {{ mb_strtoupper($ce['nombre']) }} L.{{ number_format($ce['precio'], 2) }}
                    @if ($ce['desglose'])<span style="display:block; font-size:calc(2.6vw * var(--esc)); font-weight:500; opacity:.75;">{{ $ce['desglose'] }}</span>@endif
                    @if ($ce['nota'])<span style="display:block; font-size:calc(2.3vw * var(--esc)); font-weight:500; opacity:.6;">{{ $ce['nota'] }}</span>@endif
                </div>
            @endforeach
        @endif

        @if ($platillos->isNotEmpty())
            <div class="combo-h">⭐ PLATILLOS COMPLETOS</div>
            @foreach ($platillos as $ce)
                <div class="combo">
                    {{ mb_strtoupper($ce['nombre']) }} L.{{ number_format($ce['precio'], 2) }}
                    @if ($ce['desglose'])<span style="display:block; font-size:calc(2.6vw * var(--esc)); font-weight:500; opacity:.75;">{{ $ce['desglose'] }}</span>@endif
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
