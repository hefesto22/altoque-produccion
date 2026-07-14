<x-filament-panels::page>
    {{--
        Colores que dependen del tema (modo día / noche): Filament pone la
        clase .dark en <html>, así la barra de armado y el dropdown de
        clientes se leen bien en ambos modos en vez de quedar fijos en oscuro.
    --}}
    <style>
        .pos-barra-armado {
            display: flex; flex-wrap: wrap; align-items: center; gap: .75rem;
            padding: .7rem .9rem; border: 2px solid #d946ef; border-radius: .7rem;
            background: #ffffff;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .14);
        }
        .dark .pos-barra-armado {
            background: rgba(24, 24, 32, .97);
            box-shadow: 0 6px 20px rgba(0, 0, 0, .35);
        }
        .pos-sugerencias {
            position: absolute; z-index: 60; left: 0; right: 0; margin-top: .25rem;
            border-radius: .5rem; overflow: hidden;
            background: #ffffff; border: 1px solid #cbd5e1;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .14);
        }
        .dark .pos-sugerencias {
            background: #1b2740; border-color: #2b3a59; box-shadow: none;
        }
        .pos-sugerencias button { color: #111827; }
        .dark .pos-sugerencias button { color: #e7ecf3; }
    </style>

    {{-- Todo el POS en MAYÚSCULAS para lectura rápida en caja --}}
    <div style="text-transform:uppercase;">
    {{-- Turno de caja --}}
    @if (! $turnoAbierto)
        <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; padding:.75rem 1rem; border:1px solid #f59e0b; border-radius:.6rem; background:rgba(245,158,11,.08);">
            <span style="font-weight:700;">⚠ Turno de caja cerrado</span>
            @if ($this->puedeAbrirTurno())
                <span style="opacity:.7; font-size:.85rem;">Abrí el turno para empezar a cobrar.</span>
                <x-filament::button color="success" wire:click="$set('mostrarApertura', true)" style="margin-left:auto;">Abrir turno</x-filament::button>
            @else
                <span style="opacity:.7; font-size:.85rem;">Pedile al encargado que abra tu turno con el fondo de caja.</span>
            @endif
        </div>
    @else
        {{-- Turno abierto: línea fina para no ocupar espacio --}}
        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.6rem; font-size:.76rem;">
            <span style="color:#10b981; font-weight:700;">● Turno abierto</span>
            <span style="opacity:.55;">desde {{ $turnoDesde }}</span>
            <x-filament::button size="xs" color="danger" outlined wire:click="$set('mostrarCierre', true)" style="margin-left:auto;">Cerrar turno</x-filament::button>
        </div>
    @endif

    {{-- Barra: servicio + tipo de pedido en una sola línea --}}
    <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem;">
        @if (count($servicios))
            <div style="display:flex; align-items:center; gap:.4rem; flex-wrap:wrap;">
                <span style="font-size:.78rem; opacity:.6;">Servicio:</span>
                @foreach ($servicios as $s)
                    <x-filament::button size="sm" :color="$servicioId === $s['id'] ? 'primary' : 'gray'" wire:click="cambiarServicio({{ $s['id'] }})">{{ $s['nombre'] }}</x-filament::button>
                @endforeach
            </div>
            <div style="width:1px; height:1.5rem; background:rgba(128,128,128,.3);"></div>
        @endif
        <div style="display:flex; align-items:center; gap:.4rem; flex-wrap:wrap;">
            <span style="font-size:.78rem; opacity:.6;">Orden:</span>
            @foreach (['local' => 'En el local', 'llevar' => 'Para llevar', 'domicilio' => 'A domicilio'] as $val => $lbl)
                <x-filament::button size="sm" :color="$tipoServicio === $val ? 'primary' : 'gray'" wire:click="setTipoServicio('{{ $val }}')">{{ $lbl }}</x-filament::button>
            @endforeach
        </div>
    </div>

    {{-- Nombre del cliente (local y llevar): tarjeta compacta en línea, sin
         sección completa — un dato no merece medio metro de pantalla. --}}
    @if (in_array($tipoServicio, ['llevar', 'local'], true))
        <div style="display:flex; align-items:center; gap:.8rem; margin-bottom:1.25rem; padding:.6rem .9rem; max-width:36rem;
                    border:1px solid rgba(128,128,128,.25); border-radius:.75rem; background:rgba(128,128,128,.06);">
            <x-filament::icon icon="heroicon-o-user" style="width:1.4rem; height:1.4rem; opacity:.65; flex-shrink:0;" />
            <div style="flex:1;">
                <label style="display:block; font-size:.7rem; font-weight:600; letter-spacing:.02em; opacity:.75; margin-bottom:.2rem;">
                    {{ $tipoServicio === 'llevar'
                        ? 'CLIENTE * — PARA LLAMARLO CUANDO ESTÉ LISTO'
                        : 'CLIENTE — OBLIGATORIO AL MANDAR A COCINA (SALE EN LA COMANDA)' }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="domNombre" placeholder="Nombre del cliente" />
                </x-filament::input.wrapper>
            </div>
        </div>
    @endif

    @if ($tipoServicio === 'domicilio')
        <div style="margin-bottom:1.25rem;">
            <x-filament::section>
                <x-slot name="heading">Datos del cliente (domicilio — lo lleva un repartidor)</x-slot>
                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem;">
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Nombre *</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domNombre" placeholder="Nombre del cliente" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Teléfono *</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domTelefono" placeholder="9999-9999" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Identidad / RTN (opcional)</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domIdentidad" placeholder="0801-1990-12345" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Dirección (opcional)</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domDireccion" placeholder="Dirección de entrega" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Costo del viaje (repartidor)</label>
                        <x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="costoViaje" placeholder="0.00" /></x-filament::input.wrapper>
                        <span style="font-size:.66rem; opacity:.6;">Control interno — no aparece en la factura.</span>
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    <div style="display:grid; gap:1.5rem; grid-template-columns:minmax(0,2fr) minmax(0,1fr);">

        {{-- ─────────── MENÚ ─────────── --}}
        {{-- El buscador es global: filtra platillos, proteínas, complementos,
             bebidas y extras en el navegador (Alpine), sin pegarle al server.
             El x-data vive en este div plano — nunca sobre un componente de
             Filament, que trae su propio x-data y pisaría el nuestro. --}}
        <div style="display:flex; flex-direction:column; gap:1.5rem;"
            x-data="{
                filtro: '',
                norm(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); },
                ver(n) { return this.filtro === '' || this.norm(n).includes(this.norm(this.filtro)); },
            }">

            {{-- Pedidos pendientes de pago: botón que se despliega, para no ocupar espacio --}}
            {{-- wire:poll: pendientes creados por otra caja o por pedidos online
                 aparecen solos, sin recargar la página (pedido del restaurante). --}}
            <div wire:poll.15s>
            @php($pendientes = $this->pedidosPendientes)
            @if (count($pendientes))
                <div>
                    <x-filament::button color="warning" wire:click="$toggle('mostrarPendientes')" style="width:100%; justify-content:space-between;">
                        <span>🧾 Pedidos por cobrar ({{ count($pendientes) }})</span>
                        <span>{{ $mostrarPendientes ? '▲' : '▼' }}</span>
                    </x-filament::button>
                    @if ($mostrarPendientes)
                    <div style="display:flex; flex-direction:column; gap:.5rem; margin-top:.6rem;">
                        @foreach ($pendientes as $p)
                            <div style="border:1.5px solid #f59e0b; border-radius:.6rem; padding:.6rem .75rem; display:flex; flex-wrap:wrap; align-items:center; gap:.6rem; text-transform:uppercase;">
                                <div style="flex:1 1 12rem; min-width:10rem;">
                                    <span style="font-weight:800; font-size:1.05rem;">{{ $p->numero_orden }}</span>
                                    <span style="font-size:.72rem; opacity:.7;">· {{ $p->tipo_orden }}@if ($p->nombre_cliente) · {{ $p->nombre_cliente }}@endif</span>
                                    <div style="font-size:.72rem; opacity:.7;">{{ collect($p->items)->map(fn ($i) => $i->cantidad.'× '.$i->nombre)->implode(', ') }}</div>
                                    @if ((float) $p->costo_viaje > 0)
                                        <div style="font-size:.7rem; opacity:.7;">Viaje L. {{ number_format((float) $p->costo_viaje, 2) }} (interno)</div>
                                    @endif
                                </div>
                                <span style="font-weight:800;">L. {{ number_format((float) $p->total, 2) }}</span>
                                @if ($cobrandoTransferId === $p->id)
                                    {{-- Selector de banco para cobrar por transferencia --}}
                                    <div style="display:flex; gap:.3rem; align-items:center; flex-wrap:wrap;">
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="cobroBanco">
                                                <option value="">— Banco —</option>
                                                @foreach (config('empresa.bancos', []) as $b)
                                                    <option value="{{ $b }}">{{ $b }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        <x-filament::button size="xs" color="success" wire:click="confirmarTransferenciaPendiente">Cobrar</x-filament::button>
                                        <x-filament::button size="xs" color="gray" wire:click="cancelarTransferenciaPendiente">Cancelar</x-filament::button>
                                    </div>
                                @else
                                    <div style="display:flex; gap:.3rem; flex-wrap:wrap;">
                                        <x-filament::button size="xs" color="success" wire:click="cobrarPendienteCF({{ $p->id }}, 'efectivo')">Efectivo</x-filament::button>
                                        @if ($p->tipo_orden === 'llevar')
                                            <x-filament::button size="xs" color="info" wire:click="cobrarPendienteCF({{ $p->id }}, 'tarjeta')">Tarjeta</x-filament::button>
                                        @endif
                                        <x-filament::button size="xs" color="warning" wire:click="pedirBancoPendiente({{ $p->id }}, 'transferencia')">Transferencia</x-filament::button>
                                        <x-filament::button size="xs" color="gray" outlined wire:click="facturarPendienteRtn({{ $p->id }})">Factura RTN</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endif
            </div>

            {{-- Buscador global del menú: barra completa, al ancho del menú,
                 con botón para limpiar (solo aparece cuando hay texto). --}}
            <div style="display:flex; align-items:center; gap:.6rem;">
                <div style="flex:1;">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" style="width:100%;">
                        <x-filament::input type="search" x-model="filtro"
                            placeholder="Buscar en todo el menú — platillos, proteínas, complementos, bebidas…"
                            style="width:100%; font-size:1rem; padding-top:.6rem; padding-bottom:.6rem;" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button color="gray" outlined x-show="filtro !== ''" x-on:click="filtro = ''" style="white-space:nowrap;">
                    Limpiar ✕
                </x-filament::button>
            </div>

            {{-- Platillos completos: cobro de un toque a precio fijo. --}}
            @if (count($combos))
                <x-filament::section x-show="filtro === '' || {{ \Illuminate\Support\Js::from(collect($combos)->pluck('nombre')) }}.some(n => ver(n))">
                    <x-slot name="heading">⭐ Platillos completos</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                        @foreach ($combos as $combo)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="warning"
                                x-show="ver({{ \Illuminate\Support\Js::from($combo['nombre']) }})"
                                wire:click="personalizarPlatillo({{ $combo['id'] }})">
                                <span style="display:flex; flex-direction:column; align-items:flex-start; text-align:left;">
                                    <span style="font-weight:600;">{{ $combo['nombre'] }}</span>
                                    <span style="font-size:.7rem; opacity:.8;">L. {{ number_format((float) $combo['precio'], 2) }}</span>
                                </span>
                            </x-filament::button>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section x-show="filtro === '' || {{ \Illuminate\Support\Js::from(collect($proteinas)->pluck('nombre')) }}.some(n => ver(n))">
                <x-slot name="heading">1 · Proteína</x-slot>
                <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($proteinas as $p)
                        <x-filament::button style="width:100%; justify-content:flex-start;"
                            x-show="ver({{ \Illuminate\Support\Js::from($p['nombre']) }})"
                            :color="$proteinaId === $p['id'] ? 'primary' : 'gray'"
                            wire:click="seleccionarProteina({{ $p['id'] }})">
                            <span style="display:flex; flex-direction:column; align-items:flex-start; text-align:left;">
                                <span style="font-weight:600;">{{ $p['nombre'] }}</span>
                                <span style="font-size:.7rem; opacity:.8;">L. {{ number_format((float) $p['precio'], 2) }}</span>
                            </span>
                        </x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Barra de armado FIJA: aparece al tocar la proteína y queda
                 flotando visible mientras se eligen complementos. El flujo
                 rápido (proteína → Sin/1/2/3) agrega al carrito sin scroll. --}}
            @if ($proteinaId)
                @php($prev = $this->platoPreview)
                <div style="position:sticky; top:.5rem; z-index:30;">
                    <div class="pos-barra-armado">
                        {{-- Plato en construcción: nombre + precio en vivo --}}
                        <div style="flex:1 1 12rem; min-width:11rem;">
                            <div style="font-weight:700;">{{ $prev['nombre'] }}</div>
                            <div style="font-size:.8rem; opacity:.9;">
                                L. {{ number_format($prev['precio'], 2) }}
                                @if ($prev['descuento'] > 0)
                                    · <span style="color:#16a34a; font-weight:600;">ahorro L. {{ number_format($prev['descuento'], 2) }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Atajo "por cantidad": agrega al carrito al instante --}}
                        <div style="display:flex; align-items:center; gap:.3rem;">
                            <span style="font-size:.72rem; opacity:.6;">Rápido:</span>
                            <button type="button" wire:click="agregarSinComplementos"
                                style="font-size:.78rem; padding:.4rem .6rem; border-radius:.45rem; border:1px solid rgba(128,128,128,.45); background:transparent; color:inherit; cursor:pointer;">Sin compl.</button>
                            @foreach ([1, 2, 3] as $q)
                                <button type="button" wire:click="platoRapido({{ $q }})"
                                    style="width:2.1rem; height:2.1rem; border-radius:.45rem; border:1px solid #d946ef; background:rgba(217,70,239,.12); color:inherit; cursor:pointer; font-weight:800; font-size:1rem;">{{ $q }}</button>
                            @endforeach
                        </div>

                        {{-- Cantidad de platos idénticos --}}
                        <div style="display:flex; align-items:center; gap:.3rem;">
                            <span style="font-size:.74rem; opacity:.6;">Cant.:</span>
                            <button type="button" wire:click="cambiarCantidadPlato(-1)"
                                style="width:1.6rem; height:1.6rem; border-radius:.35rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1.1rem; line-height:1;">−</button>
                            <span style="min-width:1.4rem; text-align:center; font-weight:800; font-size:1rem;">{{ $cantidadPlato }}</span>
                            <button type="button" wire:click="cambiarCantidadPlato(1)"
                                style="width:1.6rem; height:1.6rem; border-radius:.35rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1.1rem; line-height:1;">+</button>
                        </div>

                        <x-filament::button wire:click="agregarPlato" icon="heroicon-o-plus" color="primary">
                            Agregar {{ $cantidadPlato > 1 ? $cantidadPlato.' platos' : 'plato' }}
                        </x-filament::button>
                    </div>
                </div>
            @endif

            <x-filament::section x-show="filtro === '' || {{ \Illuminate\Support\Js::from(collect($complementos)->pluck('nombre')) }}.some(n => ver(n))">
                <x-slot name="heading">2 · Complementos</x-slot>
                <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($complementos as $c)
                        @php($n = $this->contarComplemento($c['id']))
                        @php($bajo = in_array($c['id'], $productosBajos, true))
                        {{-- TODA la tarjeta agrega el complemento. Los botones internos
                             (⚠, −, + Solo) usan .stop para no disparar el agregado. --}}
                        <div wire:click="agregarComplemento({{ $c['id'] }})"
                            x-show="ver({{ \Illuminate\Support\Js::from($c['nombre']) }})"
                            style="position:relative; border:1.5px solid {{ $n > 0 ? '#f59e0b' : 'rgba(128,128,128,.22)' }}; border-radius:.6rem; padding:.5rem .55rem; background:{{ $n > 0 ? 'rgba(245,158,11,.08)' : 'transparent' }}; cursor:pointer;">
                            <div style="padding-right:1.4rem;">
                                <span style="display:block; font-weight:600; font-size:.88rem;">{{ $c['nombre'] }}</span>
                                <span style="font-size:.72rem; opacity:.65;">L. {{ number_format((float) $c['precio'], 2) }}</span>
                            </div>

                            {{-- Aviso "se está acabando": ícono chico en la esquina (toggle) --}}
                            <button type="button" wire:click.stop="alternarReposicion({{ $c['id'] }})"
                                title="{{ $bajo ? 'Ya se repuso (quitar aviso)' : 'Marcar: se está acabando' }}"
                                style="position:absolute; top:.3rem; right:.3rem; width:1.35rem; height:1.35rem; line-height:1; border-radius:.35rem; border:none; cursor:pointer; font-size:.78rem; background:{{ $bajo ? '#f59e0b' : 'rgba(128,128,128,.16)' }}; color:{{ $bajo ? '#000' : 'inherit' }};">⚠</button>

                            <div style="display:flex; align-items:center; gap:.5rem; margin-top:.4rem;">
                                @if ($n > 0)
                                    <button type="button" wire:click.stop="quitarComplemento({{ $c['id'] }})"
                                        style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">−</button>
                                    <span style="font-weight:700; font-size:.85rem;">{{ $n }}</span>
                                @endif
                                <button type="button" wire:click.stop="agregarProducto({{ $c['id'] }})" title="Vender este complemento solo"
                                    style="margin-left:auto; font-size:.66rem; padding:.12rem .45rem; border-radius:.3rem; border:1px solid rgba(128,128,128,.4); background:transparent; color:inherit; cursor:pointer;">+ Solo</button>
                            </div>

                            @if ($bajo)
                                <div style="font-size:.62rem; color:#f59e0b; margin-top:.25rem;">Avisado · tocá ⚠ para quitar</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1.5rem;">
                <x-filament::section x-show="filtro === '' || {{ \Illuminate\Support\Js::from(collect($bebidas)->pluck('nombre')) }}.some(n => ver(n))">
                    <x-slot name="heading">Bebidas (ISV)</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                        @forelse ($bebidas as $b)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="gray"
                                x-show="ver({{ \Illuminate\Support\Js::from($b['nombre']) }})"
                                wire:click="agregarProducto({{ $b['id'] }})">
                                <span style="display:flex; flex-direction:column; align-items:flex-start; text-align:left;">
                                    <span style="font-weight:600;">{{ $b['nombre'] }}</span>
                                    <span style="font-size:.7rem; opacity:.8;">L. {{ number_format((float) $b['precio'], 2) }}</span>
                                </span>
                            </x-filament::button>
                        @empty
                            <p style="font-size:.8rem; opacity:.6; grid-column:1/-1;">Sin bebidas cargadas.</p>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section x-show="filtro === '' || {{ \Illuminate\Support\Js::from(collect($extras)->pluck('nombre')) }}.some(n => ver(n))">
                    <x-slot name="heading">Extras</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                        @forelse ($extras as $e)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="gray"
                                x-show="ver({{ \Illuminate\Support\Js::from($e['nombre']) }})"
                                wire:click="agregarProducto({{ $e['id'] }})">
                                <span style="display:flex; flex-direction:column; align-items:flex-start; text-align:left;">
                                    <span style="font-weight:600;">{{ $e['nombre'] }}</span>
                                    <span style="font-size:.7rem; opacity:.8;">L. {{ number_format((float) $e['precio'], 2) }}</span>
                                </span>
                            </x-filament::button>
                        @empty
                            <p style="font-size:.8rem; opacity:.6; grid-column:1/-1;">Sin extras cargados.</p>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- ─────────── CARRITO ─────────── --}}
        <div>
            <x-filament::section>
                <x-slot name="heading">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <span>Venta actual</span>
                        @if (count($carrito))
                            <x-filament::button size="xs" color="danger" outlined wire:click="limpiar">Vaciar</x-filament::button>
                        @endif
                    </div>
                </x-slot>

                <div style="display:flex; flex-direction:column; gap:.5rem; max-height:20rem; overflow-y:auto;">
                    @forelse ($this->carritoAgrupado as $g)
                        @php($p = $g['principal'])
                        @php($grupo = $p['grupo'] ?? $p['key'])
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem;">
                            <div style="flex:1;">
                                <div style="font-weight:600;">{{ $p['nombre'] }}</div>
                                @if (! empty($p['detalle']))
                                    <div style="font-size:.72rem; opacity:.7;">{{ implode(', ', $p['detalle']) }}</div>
                                @endif
                                @if (! empty($p['nota']))
                                    <div style="font-size:.72rem; color:#f59e0b;">📝 {{ $p['nota'] }}</div>
                                @endif
                                {{-- Extras del platillo, anidados debajo (mismo grupo) --}}
                                @foreach ($g['extras'] as $ex)
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:.4rem; margin-top:.28rem; padding-left:.45rem; border-left:2px solid #f59e0b; font-size:.75rem;">
                                        <span style="opacity:.9;">+ {{ $ex['nombre'] }}</span>
                                        <span style="display:flex; align-items:center; gap:.35rem; white-space:nowrap;">
                                            L. {{ number_format((float) $ex['precio'] * (int) $ex['cantidad'], 2) }}
                                            <button type="button" wire:click="quitarLinea('{{ $ex['key'] }}')" style="border:none; background:none; color:#ef4444; cursor:pointer; font-weight:700;">×</button>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:.35rem; white-space:nowrap;">
                                <span style="font-weight:700;">L. {{ number_format($g['total'], 2) }}</span>
                                <div style="display:flex; align-items:center; gap:.3rem;">
                                    @if (count($g['extras']) === 0)
                                        <button type="button" wire:click="cambiarCantidad('{{ $p['key'] }}', -1)"
                                            style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">−</button>
                                        <span style="min-width:1.2rem; text-align:center; font-weight:700;">{{ $p['cantidad'] }}</span>
                                        <button type="button" wire:click="cambiarCantidad('{{ $p['key'] }}', 1)"
                                            style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">+</button>
                                    @endif
                                    <x-filament::icon-button icon="heroicon-o-x-mark" color="danger" wire:click="quitarGrupo('{{ $grupo }}')" label="Quitar" />
                                </div>
                            </div>
                        </div>
                    @empty
                        <p style="text-align:center; padding:1.5rem 0; opacity:.6; font-size:.9rem;">Aún no hay nada en la venta.</p>
                    @endforelse
                </div>

                <div style="margin-top:1rem; padding-top:.75rem; border-top:1px solid rgba(128,128,128,.25); display:flex; flex-direction:column; gap:.25rem; font-size:.9rem;">
                    @if ($this->resumen['descuento'] > 0)
                        <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Precio normal</span><span>L. {{ number_format($this->resumen['subtotal_lista'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; color:#16a34a; font-weight:600;"><span>Descuento</span><span>− L. {{ number_format($this->resumen['descuento'], 2) }}</span></div>
                    @endif
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Exento</span><span>L. {{ number_format($this->resumen['exento'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Gravado</span><span>L. {{ number_format($this->resumen['gravado'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>ISV (15%)</span><span>L. {{ number_format($this->resumen['isv'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; font-size:1.15rem; font-weight:700; padding-top:.25rem;"><span>Total</span><span>L. {{ number_format($this->resumen['total'], 2) }}</span></div>
                </div>

                <div style="display:flex; align-items:center; gap:.4rem; margin-top:.75rem; flex-wrap:wrap;">
                    <span style="font-size:.78rem; opacity:.6;">Pago:</span>
                    @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transf.', 'mixto' => 'Mixto'] as $fp => $lbl)
                        <x-filament::button size="xs" :color="$formaPago === $fp ? 'primary' : 'gray'" wire:click="$set('formaPago','{{ $fp }}')">{{ $lbl }}</x-filament::button>
                    @endforeach
                </div>

                @if ($formaPago === 'mixto')
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:.4rem; margin-top:.5rem;">
                        @foreach (['mixtoEfectivo' => 'Efectivo', 'mixtoTarjeta' => 'Tarjeta', 'mixtoTransfer' => 'Transf.'] as $campo => $lbl)
                            <div>
                                <label style="display:block; font-size:.72rem; opacity:.6; margin-bottom:.15rem;">{{ $lbl }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="number" step="0.01" min="0" wire:model.live="{{ $campo }}" placeholder="0.00" />
                                </x-filament::input.wrapper>
                            </div>
                        @endforeach
                    </div>
                    @php($restante = $this->mixtoRestante)
                    <div style="margin-top:.35rem; font-size:.8rem; font-weight:600; color: {{ abs($restante) < 0.01 ? '#16a34a' : ($restante < 0 ? '#dc2626' : '#d97706') }};">
                        @if (abs($restante) < 0.01) ✓ Los montos cuadran con el total
                        @elseif ($restante > 0) Falta L. {{ number_format($restante, 2) }}
                        @else Se pasó por L. {{ number_format(abs($restante), 2) }}
                        @endif
                    </div>
                @endif

                @if ($formaPago === 'transferencia' || ($formaPago === 'mixto' && is_numeric($mixtoTransfer) && (float) $mixtoTransfer > 0))
                    <div style="margin-top:.5rem;">
                        <label style="display:block; font-size:.74rem; opacity:.6; margin-bottom:.2rem;">Banco de la transferencia</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="banco">
                                <option value="">— Elegí el banco —</option>
                                @foreach (config('empresa.bancos', []) as $b)
                                    <option value="{{ $b }}">{{ $b }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif

                <div style="display:flex; flex-direction:column; gap:.5rem; margin-top:.75rem;">
                    <x-filament::button wire:click="facturarConsumidorFinal" color="primary" size="lg" style="width:100%;">Cobrar y Facturar</x-filament::button>
                    <x-filament::button wire:click="abrirFactura" color="gray" outlined size="lg" style="width:100%;">Factura con RTN</x-filament::button>
                    <x-filament::button wire:click="pagarDespues" color="warning" outlined size="lg" style="width:100%;">Pagar después (a cocina)</x-filament::button>
                </div>
            </x-filament::section>
        </div>
    </div>

    {{-- La impresión directa vive en el script global (render hook en AppServiceProvider) --}}


    {{-- ─────────── MODAL CIERRE DE TURNO ─────────── --}}
    {{-- ─────────── MODAL PERSONALIZAR PLATILLO ─────────── --}}
    @if ($personalizando)
        @php($prev = $this->platilloResumen)
        <div style="position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.5); padding:1rem;">
            <div style="width:100%; max-width:40rem; max-height:90vh; overflow-y:auto;">
                <x-filament::section>
                    <x-slot name="heading">Personalizar: {{ $platilloNombre }}</x-slot>
                    <x-slot name="description">
                        @php($bebTxt = $platilloBase['bebida'] > 0 ? ' · '.$platilloBase['bebida'].' bebida(s)' : '')
                        Base: {{ $platilloBase['carne'] }} carne · {{ $platilloBase['complemento'] }} complementos{{ $bebTxt }} — L. {{ number_format($platilloPrecio, 2) }}. Cambiá lo que quieras; lo que pase de la base se cobra extra.
                    </x-slot>

                    {{-- Selección actual --}}
                    <div style="margin-bottom:.6rem;">
                        <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:.74rem; margin-bottom:.25rem;">
                            <span style="opacity:.6;">Lleva:</span>
                            <span style="opacity:.75;">Carne {{ $prev['carne'] }}/{{ $platilloBase['carne'] }} · Compl. {{ $prev['complemento'] }}/{{ $platilloBase['complemento'] }}{{ $platilloBase['bebida'] > 0 ? ' · Beb. '.$prev['bebida'].'/'.$platilloBase['bebida'] : '' }}</span>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:.35rem;">
                            @forelse ($platilloSel as $i => $s)
                                @php($esExtra = in_array($i, $prev['extra_indices'], true))
                                <span style="display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .5rem; border:1px solid {{ $esExtra ? '#f59e0b' : 'rgba(128,128,128,.35)' }}; background:{{ $esExtra ? 'rgba(245,158,11,.1)' : 'transparent' }}; border-radius:999px; font-size:.82rem;">
                                    {{ $s['nombre'] }}{{ $esExtra ? ' · extra L.'.number_format((float) $s['precio'], 0) : '' }}
                                    <button type="button" wire:click="platilloQuitar({{ $i }})" style="border:none; background:none; color:#ef4444; cursor:pointer; font-weight:700;">×</button>
                                </span>
                            @empty
                                <span style="opacity:.55; font-size:.82rem;">Nada seleccionado.</span>
                            @endforelse
                        </div>
                    </div>

                    {{-- Agregar / cambiar productos --}}
                    <div style="border-top:1px solid rgba(128,128,128,.2); padding-top:.5rem;">
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model.live.debounce.250ms="platilloBuscar" placeholder="🔍 Buscar producto…" />
                        </x-filament::input.wrapper>
                    </div>
                    @php($q = mb_strtolower(trim($platilloBuscar)))
                    <div style="display:flex; flex-direction:column; gap:.5rem; margin-top:.4rem; max-height:32vh; overflow-y:auto;">
                        @foreach (['Proteínas' => $proteinas, 'Complementos' => $complementos, 'Bebidas' => $bebidas] as $titulo => $lista)
                            @php($filtrados = $q === '' ? $lista : collect($lista)->filter(fn ($p) => str_contains(mb_strtolower((string) $p['nombre']), $q))->all())
                            @if (count($filtrados))
                                <div>
                                    <div style="font-size:.72rem; opacity:.6; margin-bottom:.2rem;">{{ $titulo }}</div>
                                    <div style="display:flex; flex-wrap:wrap; gap:.3rem;">
                                        @foreach ($filtrados as $prod)
                                            <button type="button" wire:click="platilloAgregar({{ $prod['id'] }})"
                                                style="font-size:.76rem; padding:.25rem .5rem; border-radius:.4rem; border:1px solid rgba(128,128,128,.35); background:transparent; color:inherit; cursor:pointer;">
                                                {{ $prod['nombre'] }} <span style="opacity:.5;">L.{{ number_format((float) $prod['precio'], 0) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        @if ($q !== '' && collect($proteinas)->merge($complementos)->merge($bebidas)->filter(fn ($p) => str_contains(mb_strtolower((string) $p['nombre']), $q))->isEmpty())
                            <div style="opacity:.55; font-size:.82rem; text-align:center; padding:.5rem;">Sin resultados para “{{ $platilloBuscar }}”.</div>
                        @endif
                    </div>

                    {{-- Nota --}}
                    <div style="margin-top:.6rem;">
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Nota para cocina (opcional)</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="platilloNota" placeholder="Ej: sin cebolla, bien cocido" /></x-filament::input.wrapper>
                    </div>

                    {{-- Resumen de precio --}}
                    <div style="margin-top:.7rem; padding-top:.5rem; border-top:1px solid rgba(128,128,128,.25); display:flex; flex-direction:column; gap:.2rem; font-size:.9rem;">
                        <div style="display:flex; justify-content:space-between; opacity:.8;"><span>Precio base</span><span>L. {{ number_format($platilloPrecio, 2) }}</span></div>
                        @if ($prev['extras'] > 0)
                            <div style="display:flex; justify-content:space-between; color:#16a34a;"><span>Extras ({{ $prev['extras'] }})</span><span>+ L. {{ number_format($prev['precio_extras'], 2) }}</span></div>
                        @endif
                        <div style="display:flex; justify-content:space-between; font-weight:800; font-size:1.05rem;"><span>Total</span><span>L. {{ number_format($prev['total'], 2) }}</span></div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.8rem;">
                        <x-filament::button color="gray" wire:click="cancelarPlatillo">Cancelar</x-filament::button>
                        <x-filament::button color="primary" wire:click="confirmarPlatillo">Agregar al carrito</x-filament::button>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif

    {{-- ─────────── MODAL APERTURA DE TURNO ─────────── --}}
    @if ($mostrarApertura && $this->puedeAbrirTurno())
        <div style="position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.5); padding:1rem;">
            <div style="width:100%; max-width:26rem;">
                <x-filament::section>
                    <x-slot name="heading">Abrir turno de caja</x-slot>
                    <x-slot name="description">Con cuánto arranca la caja al iniciar el POS.</x-slot>

                    <div style="display:flex; flex-direction:column; gap:.8rem;">
                        <div>
                            <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Efectivo inicial en caja</label>
                            <x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="fondoInicial" placeholder="0.00" /></x-filament::input.wrapper>
                            <span style="font-size:.7rem; opacity:.6;">El efectivo (vuelto) con que arranca la gaveta.</span>
                        </div>
                        <div>
                            <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Saldo inicial del terminal POS</label>
                            <x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="fondoTerminal" placeholder="0.00" /></x-filament::input.wrapper>
                            <span style="font-size:.7rem; opacity:.6;">Lo que quedó en el terminal de tarjeta/transferencias sin cortar (si aplica).</span>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem;">
                        <x-filament::button color="gray" wire:click="$set('mostrarApertura', false)">Cancelar</x-filament::button>
                        <x-filament::button color="success" wire:click="abrirTurno">Abrir turno</x-filament::button>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif

    {{-- ─────────── MODAL CIERRE DE TURNO ─────────── --}}
    @if ($mostrarCierre)
        @php($rt = $this->resumenTurno)
        <div style="position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.5); padding:1rem;">
            <div style="width:100%; max-width:30rem;">
                <x-filament::section>
                    <x-slot name="heading">Cerrar turno de caja</x-slot>
                    <x-slot name="description">Conciliá el efectivo antes de cerrar.</x-slot>

                    <div style="display:flex; flex-direction:column; gap:.3rem; font-size:.9rem;">
                        <div style="display:flex; justify-content:space-between;"><span>Ventas del turno</span><span>{{ $rt['ventas'] }}</span></div>
                        <div style="display:flex; justify-content:space-between;"><span>Total vendido</span><span>L. {{ number_format($rt['total'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; opacity:.75;"><span>· Efectivo</span><span>L. {{ number_format($rt['efectivo'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; opacity:.75;"><span>· Tarjeta</span><span>L. {{ number_format($rt['tarjeta'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; opacity:.75;"><span>· Transferencia</span><span>L. {{ number_format($rt['transferencia'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between;"><span>Fondo inicial</span><span>L. {{ number_format($rt['fondo'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; font-weight:700; border-top:1px solid rgba(128,128,128,.25); padding-top:.3rem;"><span>Efectivo esperado en caja</span><span>L. {{ number_format($rt['esperado'], 2) }}</span></div>

                        {{-- Terminal POS: desglose explícito, mismo formato que el efectivo --}}
                        <div style="border-top:1px solid rgba(128,128,128,.25); margin-top:.4rem; padding-top:.4rem;">
                            <div style="font-size:.72rem; font-weight:700; opacity:.55; text-transform:uppercase; letter-spacing:.03em; margin-bottom:.2rem;">Terminal POS</div>
                            <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Saldo inicial del terminal</span><span>L. {{ number_format($rt['terminal_inicial'], 2) }}</span></div>
                            <div style="display:flex; justify-content:space-between; opacity:.75;"><span>+ Tarjeta del turno</span><span>L. {{ number_format($rt['tarjeta'], 2) }}</span></div>
                            <div style="display:flex; justify-content:space-between; opacity:.75;"><span>+ Transferencias del turno</span><span>L. {{ number_format($rt['transferencia'], 2) }}</span></div>
                            <div style="display:flex; justify-content:space-between; font-weight:700; border-top:1px dashed rgba(128,128,128,.25); margin-top:.2rem; padding-top:.2rem;"><span>Nuevo saldo en terminal POS</span><span>L. {{ number_format($rt['terminal_final'], 2) }}</span></div>
                        </div>

                        @if (count($rt['tarjeta_banco']) || count($rt['transfer_banco']))
                            <div style="border-top:1px dashed rgba(128,128,128,.25); margin-top:.3rem; padding-top:.3rem;">
                                @foreach ($rt['tarjeta_banco'] as $tb)
                                    <div style="display:flex; justify-content:space-between; opacity:.75; font-size:.82rem;"><span>Tarjeta · {{ $tb['banco'] }}</span><span>L. {{ number_format($tb['total'], 2) }}</span></div>
                                @endforeach
                                @foreach ($rt['transfer_banco'] as $tb)
                                    <div style="display:flex; justify-content:space-between; opacity:.75; font-size:.82rem;"><span>Transf. · {{ $tb['banco'] }}</span><span>L. {{ number_format($tb['total'], 2) }}</span></div>
                                @endforeach
                            </div>
                        @endif

                        @if ($rt['dom_viaje_transfer'] > 0)
                            <div style="display:flex; justify-content:space-between; font-weight:700; color:#f59e0b; border-top:1px dashed rgba(245,158,11,.4); margin-top:.3rem; padding-top:.3rem;">
                                <span>🛵 A pagar a repartidores (viajes a domicilio)</span><span>L. {{ number_format($rt['dom_viaje_transfer'], 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <div style="margin-top:1rem;">
                        <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Efectivo contado *</label>
                        <x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="efectivoContado" placeholder="0.00" /></x-filament::input.wrapper>
                        <label style="display:block; font-size:.8rem; font-weight:600; margin:.6rem 0 .25rem;">Notas (opcional)</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="notasCierre" placeholder="Observaciones del turno" /></x-filament::input.wrapper>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem;">
                        <x-filament::button color="gray" wire:click="$set('mostrarCierre', false)">Cancelar</x-filament::button>
                        <x-filament::button color="danger" wire:click="confirmarCierre">Cerrar turno</x-filament::button>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif

    {{-- ─────────── MODAL FACTURA ─────────── --}}
    @if ($mostrarFactura)
        <div style="position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.5); padding:1rem;">
            <div style="width:100%; max-width:28rem;">
                <x-filament::section>
                    <x-slot name="heading">Emitir factura SAR</x-slot>
                    <x-slot name="description">Total a facturar: L. {{ number_format($this->totalModal, 2) }}</x-slot>

                    <div style="display:flex; flex-direction:column; gap:.75rem;">
                        <div>
                            <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">RTN del cliente</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model.live.debounce.500ms="rtnInput" maxlength="14" placeholder="08011985012345" />
                            </x-filament::input.wrapper>
                        </div>
                        <div style="position:relative;">
                            <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Nombre / Razón social</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model.live.debounce.400ms="nombreInput" placeholder="Empezá a escribir el nombre…" style="text-transform:uppercase;" />
                            </x-filament::input.wrapper>

                            @if (count($sugerencias))
                                <div class="pos-sugerencias">
                                    @foreach ($sugerencias as $s)
                                        <button type="button" wire:click="elegirCliente('{{ $s['rtn'] }}', @js($s['nombre']))"
                                            style="display:block; width:100%; text-align:left; padding:.5rem .6rem; background:none; border:none; cursor:pointer; border-bottom:1px solid rgba(128,128,128,.15);">
                                            <span style="font-weight:600;">{{ $s['nombre'] }}</span>
                                            <span style="display:block; font-size:.72rem; opacity:.7;">RTN: {{ $s['rtn'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        {{-- Forma de pago de la factura (efectivo / tarjeta / transferencia) --}}
                        <div>
                            <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Forma de pago</label>
                            <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                                @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transf.', 'mixto' => 'Mixto'] as $fp => $lbl)
                                    <x-filament::button size="sm" :color="$formaPago === $fp ? 'primary' : 'gray'" wire:click="$set('formaPago','{{ $fp }}')">{{ $lbl }}</x-filament::button>
                                @endforeach
                            </div>
                            @if ($formaPago === 'mixto')
                                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:.4rem; margin-top:.5rem;">
                                    @foreach (['mixtoEfectivo' => 'Efectivo', 'mixtoTarjeta' => 'Tarjeta', 'mixtoTransfer' => 'Transf.'] as $campo => $lbl)
                                        <div>
                                            <label style="display:block; font-size:.72rem; opacity:.6; margin-bottom:.15rem;">{{ $lbl }}</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="number" step="0.01" min="0" wire:model.live="{{ $campo }}" placeholder="0.00" />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endforeach
                                </div>
                                @php($restanteModal = $this->mixtoRestante)
                                <div style="margin-top:.35rem; font-size:.8rem; font-weight:600; color: {{ abs($restanteModal) < 0.01 ? '#16a34a' : ($restanteModal < 0 ? '#dc2626' : '#d97706') }};">
                                    @if (abs($restanteModal) < 0.01) ✓ Los montos cuadran con el total (L. {{ number_format($this->totalModal, 2) }})
                                    @elseif ($restanteModal > 0) Falta L. {{ number_format($restanteModal, 2) }} de L. {{ number_format($this->totalModal, 2) }}
                                    @else Se pasó por L. {{ number_format(abs($restanteModal), 2) }}
                                    @endif
                                </div>
                            @endif
                            @if ($formaPago === 'transferencia' || ($formaPago === 'mixto' && is_numeric($mixtoTransfer) && (float) $mixtoTransfer > 0))
                                <div style="margin-top:.5rem;">
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="banco">
                                            <option value="">— Banco de la transferencia —</option>
                                            @foreach (config('empresa.bancos', []) as $b)
                                                <option value="{{ $b }}">{{ $b }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @endif
                        </div>
                        <label style="display:flex; align-items:center; gap:.5rem; cursor:pointer; padding:.5rem; border:1px solid rgba(128,128,128,.25); border-radius:.5rem;">
                            <input type="checkbox" wire:model="facturaDetallada" style="width:1.1rem; height:1.1rem;" />
                            <span style="font-size:.85rem;">Detallar productos en la factura<br><span style="font-size:.72rem; opacity:.65;">Si no, se factura como “Alimentación”</span></span>
                        </label>
                        <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.5rem;">
                            <x-filament::button color="gray" wire:click="cerrarModalFactura">Cancelar</x-filament::button>
                            <x-filament::button color="primary" wire:click="emitirFactura">Emitir factura</x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif
    </div>
</x-filament-panels::page>
