<div class="wrap">
    <div class="top">
        <h1>{{ config('empresa.nombre') }}</h1>
        <p>Pedí en línea · {{ $servicioNombre }}</p>
    </div>

    @if ($enviado)
        <div class="card ok-box">
            <div class="num">✓ Pedido enviado</div>
            <p>Tu número de pedido es <strong>{{ $pedidoNumero }}</strong>.</p>
            <p class="muted">Lo estamos confirmando. Te contactaremos al teléfono que dejaste.</p>
            <p style="margin-top:1.5rem;"><a href="{{ url('/pedir') }}" class="btn alt">Hacer otro pedido</a></p>
        </div>
    @else
        <div class="grid">
            {{-- Menú --}}
            <div>
                <div class="card">
                    <h2>1 · Elegí tu proteína</h2>
                    <div class="menu-grid">
                        @forelse ($proteinas as $p)
                            <button type="button" class="tile {{ $proteinaId === $p['id'] ? 'sel' : '' }}" wire:click="seleccionarProteina({{ $p['id'] }})">
                                <span class="n">{{ $p['nombre'] }}</span>
                                <span class="p">L. {{ number_format((float) $p['precio'], 2) }}</span>
                            </button>
                        @empty
                            <p class="muted">No hay menú cargado para este momento.</p>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <h2>2 · Complementos</h2>
                    <div class="menu-grid">
                        @foreach ($complementos as $c)
                            @php($n = $this->contarComplemento($c['id']))
                            <div class="tile {{ $n > 0 ? 'sel' : '' }}">
                                <button type="button" style="all:unset; cursor:pointer; display:block;" wire:click="agregarComplemento({{ $c['id'] }})">
                                    <span class="n">{{ $c['nombre'] }} @if ($n > 0)<span class="badge">{{ $n }}</span>@endif</span>
                                    <span class="p">L. {{ number_format((float) $c['precio'], 2) }}</span>
                                </button>
                                @if ($n > 0)
                                    <button type="button" class="btn alt" style="padding:.1rem .5rem; margin-top:.35rem; font-size:.72rem;" wire:click="quitarComplemento({{ $c['id'] }})">− Quitar</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div style="margin-top:.75rem;">
                        <button type="button" class="btn" wire:click="agregarPlato">+ Agregar plato</button>
                    </div>
                </div>

                @if (count($bebidas) || count($extras))
                    <div class="card">
                        <h2>Bebidas y extras</h2>
                        <div class="menu-grid">
                            @foreach (array_merge($bebidas, $extras) as $b)
                                <button type="button" class="tile" wire:click="agregarProducto({{ $b['id'] }})">
                                    <span class="n">{{ $b['nombre'] }}</span>
                                    <span class="p">L. {{ number_format((float) $b['precio'], 2) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Carrito + datos --}}
            <div>
                <div class="card">
                    <h2>Tu pedido</h2>
                    @forelse ($carrito as $item)
                        <div class="line">
                            <div>
                                <strong>{{ $item['nombre'] }}</strong>
                                @if (! empty($item['detalle']))
                                    <div class="muted">{{ implode(', ', $item['detalle']) }}</div>
                                @endif
                            </div>
                            <div style="white-space:nowrap;">
                                L. {{ number_format((float) $item['precio'] * (int) $item['cantidad'], 2) }}
                                <button type="button" class="x" wire:click="quitarLinea('{{ $item['key'] }}')">✕</button>
                            </div>
                        </div>
                    @empty
                        <p class="muted">Tu pedido está vacío.</p>
                    @endforelse

                    <div style="display:flex; justify-content:space-between; margin-top:.6rem;">
                        <span class="total">Total</span><span class="total">L. {{ number_format($this->total, 2) }}</span>
                    </div>
                </div>

                <div class="card">
                    <h2>Tus datos</h2>

                    @error('form')<div class="err">{{ $message }}</div>@enderror

                    <div class="seg">
                        <button type="button" class="btn {{ $tipo === 'domicilio' ? '' : 'alt' }}" wire:click="$set('tipo','domicilio')">🛵 Domicilio</button>
                        <button type="button" class="btn {{ $tipo === 'retiro' ? '' : 'alt' }}" wire:click="$set('tipo','retiro')">🏃 Retiro</button>
                    </div>

                    <label>Nombre *</label>
                    <input type="text" wire:model="nombre" placeholder="Tu nombre">

                    <label>Teléfono *</label>
                    <input type="text" wire:model="telefono" placeholder="9999-9999">

                    <label>Identidad (opcional)</label>
                    <input type="text" wire:model="identidad" placeholder="0801-1990-12345">

                    @if ($tipo === 'domicilio')
                        <label>Dirección de entrega *</label>
                        <input type="text" wire:model="direccion" placeholder="Barrio, calle, referencia">
                    @endif

                    <label>Forma de pago</label>
                    <div class="seg">
                        <button type="button" class="btn {{ $metodoPago === 'efectivo' ? '' : 'alt' }}" wire:click="$set('metodoPago','efectivo')">Efectivo</button>
                        <button type="button" class="btn {{ $metodoPago === 'transferencia' ? '' : 'alt' }}" wire:click="$set('metodoPago','transferencia')">Transferencia</button>
                    </div>

                    @if ($metodoPago === 'transferencia')
                        <label>Comprobante de transferencia *</label>
                        <input type="file" wire:model="comprobante" accept="image/*">
                        <div wire:loading wire:target="comprobante" class="muted">Subiendo imagen…</div>
                    @endif

                    <label>Notas (opcional)</label>
                    <textarea wire:model="notas" rows="2" placeholder="Sin cebolla, tocar el timbre, etc."></textarea>

                    <div style="margin-top:1rem;">
                        <button type="button" class="btn green full" wire:click="enviar" wire:loading.attr="disabled">Enviar pedido</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
