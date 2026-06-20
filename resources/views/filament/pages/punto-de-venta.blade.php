<x-filament-panels::page>
    {{-- Turno de caja --}}
    @if (! $turnoAbierto)
        <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; padding:.75rem 1rem; border:1px solid #f59e0b; border-radius:.6rem; background:rgba(245,158,11,.08);">
            <span style="font-weight:700;">⚠ Turno de caja cerrado</span>
            <span style="opacity:.7; font-size:.85rem;">Abrí el turno para empezar a cobrar.</span>
            <div style="display:flex; align-items:center; gap:.4rem; margin-left:auto;">
                <span style="font-size:.8rem;">Fondo inicial:</span>
                <div style="max-width:8rem;"><x-filament::input.wrapper><x-filament::input type="number" step="0.01" wire:model="fondoInicial" placeholder="0.00" /></x-filament::input.wrapper></div>
                <x-filament::button color="success" wire:click="abrirTurno">Abrir turno</x-filament::button>
            </div>
        </div>
    @else
        <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; padding:.5rem 1rem; border:1px solid #10b981; border-radius:.6rem; background:rgba(16,185,129,.08);">
            <span style="font-weight:700; color:#10b981;">● Turno abierto</span>
            <span style="opacity:.7; font-size:.8rem;">desde {{ $turnoDesde }}</span>
            <x-filament::button size="sm" color="danger" outlined wire:click="$set('mostrarCierre', true)" style="margin-left:auto;">Cerrar turno</x-filament::button>
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
            @foreach (['local' => 'En el local', 'domicilio' => 'A domicilio'] as $val => $lbl)
                <x-filament::button size="sm" :color="$tipoServicio === $val ? 'primary' : 'gray'" wire:click="setTipoServicio('{{ $val }}')">{{ $lbl }}</x-filament::button>
            @endforeach
        </div>
    </div>

    @if ($tipoServicio === 'domicilio')
        <div style="margin-bottom:1.25rem;">
            <x-filament::section>
                <x-slot name="heading">Datos del cliente (domicilio)</x-slot>
                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem;">
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Nombre</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domNombre" placeholder="Nombre del cliente" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Teléfono *</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domTelefono" placeholder="9999-9999" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Identidad</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domIdentidad" placeholder="0801-1990-12345" /></x-filament::input.wrapper>
                    </div>
                    <div>
                        <label style="display:block; font-size:.78rem; font-weight:600; margin-bottom:.2rem;">Dirección *</label>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="domDireccion" placeholder="Dirección de entrega" /></x-filament::input.wrapper>
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    <div style="display:grid; gap:1.5rem; grid-template-columns:minmax(0,2fr) minmax(0,1fr);">

        {{-- ─────────── MENÚ ─────────── --}}
        <div style="display:flex; flex-direction:column; gap:1.5rem;">

            {{-- Combos especiales: cobro de un toque a precio fijo --}}
            @if (count($combos))
                <x-filament::section>
                    <x-slot name="heading">⭐ Combos especiales</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                        @foreach ($combos as $combo)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="warning"
                                wire:click="agregarProducto({{ $combo['id'] }})">
                                <span style="display:flex; flex-direction:column; align-items:flex-start; text-align:left;">
                                    <span style="font-weight:600;">{{ $combo['nombre'] }}</span>
                                    <span style="font-size:.7rem; opacity:.8;">L. {{ number_format((float) $combo['precio'], 2) }}</span>
                                </span>
                            </x-filament::button>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">1 · Proteína</x-slot>
                <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($proteinas as $p)
                        <x-filament::button style="width:100%; justify-content:flex-start;"
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

            <x-filament::section>
                <x-slot name="heading">2 · Complementos</x-slot>
                <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem;">
                    @foreach ($complementos as $c)
                        @php($n = $this->contarComplemento($c['id']))
                        @php($bajo = in_array($c['id'], $productosBajos, true))
                        <div style="position:relative; border:1.5px solid {{ $n > 0 ? '#f59e0b' : 'rgba(128,128,128,.22)' }}; border-radius:.6rem; padding:.5rem .55rem; background:{{ $n > 0 ? 'rgba(245,158,11,.08)' : 'transparent' }};">
                            <button type="button" wire:click="agregarComplemento({{ $c['id'] }})"
                                style="display:block; width:100%; text-align:left; background:none; border:none; cursor:pointer; color:inherit; padding:0; padding-right:1.4rem;">
                                <span style="display:block; font-weight:600; font-size:.88rem;">{{ $c['nombre'] }}</span>
                                <span style="font-size:.72rem; opacity:.65;">L. {{ number_format((float) $c['precio'], 2) }}</span>
                            </button>

                            {{-- Aviso "se está acabando": ícono chico en la esquina (toggle) --}}
                            <button type="button" wire:click="alternarReposicion({{ $c['id'] }})"
                                title="{{ $bajo ? 'Ya se repuso (quitar aviso)' : 'Marcar: se está acabando' }}"
                                style="position:absolute; top:.3rem; right:.3rem; width:1.35rem; height:1.35rem; line-height:1; border-radius:.35rem; border:none; cursor:pointer; font-size:.78rem; background:{{ $bajo ? '#f59e0b' : 'rgba(128,128,128,.16)' }}; color:{{ $bajo ? '#000' : 'inherit' }};">⚠</button>

                            <div style="display:flex; align-items:center; gap:.5rem; margin-top:.4rem;">
                                @if ($n > 0)
                                    <button type="button" wire:click="quitarComplemento({{ $c['id'] }})"
                                        style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">−</button>
                                    <span style="font-weight:700; font-size:.85rem;">{{ $n }}</span>
                                @endif
                                <button type="button" wire:click="agregarProducto({{ $c['id'] }})" title="Vender este complemento solo"
                                    style="margin-left:auto; font-size:.66rem; padding:.12rem .45rem; border-radius:.3rem; border:1px solid rgba(128,128,128,.4); background:transparent; color:inherit; cursor:pointer;">+ Solo</button>
                            </div>

                            @if ($bajo)
                                <div style="font-size:.62rem; color:#f59e0b; margin-top:.25rem;">Avisado · tocá ⚠ para quitar</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; padding-top:1rem; border-top:1px solid rgba(128,128,128,.25);">
                    <span style="font-size:.9rem; opacity:.85;">
                        @if ($proteinaId)
                            Plato: <strong>{{ collect($proteinas)->firstWhere('id', $proteinaId)['nombre'] ?? '' }}</strong>
                            + {{ count($complementoSel) }} complemento(s)
                        @else
                            Seleccioná una proteína para armar un plato
                        @endif
                    </span>
                    <div style="display:flex; align-items:center; gap:.6rem;">
                        <div style="display:flex; align-items:center; gap:.3rem;">
                            <span style="font-size:.78rem; opacity:.6;">Cantidad:</span>
                            <button type="button" wire:click="cambiarCantidadPlato(-1)"
                                style="width:1.6rem; height:1.6rem; border-radius:.35rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1.1rem; line-height:1;">−</button>
                            <span style="min-width:1.4rem; text-align:center; font-weight:800; font-size:1rem;">{{ $cantidadPlato }}</span>
                            <button type="button" wire:click="cambiarCantidadPlato(1)"
                                style="width:1.6rem; height:1.6rem; border-radius:.35rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1.1rem; line-height:1;">+</button>
                        </div>
                        <x-filament::button wire:click="agregarPlato" icon="heroicon-o-plus">
                            Agregar {{ $cantidadPlato > 1 ? $cantidadPlato.' platos' : 'plato' }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1.5rem;">
                <x-filament::section>
                    <x-slot name="heading">Bebidas (ISV)</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                        @forelse ($bebidas as $b)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="gray"
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

                <x-filament::section>
                    <x-slot name="heading">Extras</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem;">
                        @forelse ($extras as $e)
                            <x-filament::button style="width:100%; justify-content:flex-start;" color="gray"
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
                    @forelse ($carrito as $item)
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; padding:.5rem; border:1px solid rgba(128,128,128,.2); border-radius:.5rem;">
                            <div>
                                <div style="font-weight:600;">{{ $item['nombre'] }}</div>
                                @if (! empty($item['detalle']))
                                    <div style="font-size:.72rem; opacity:.7;">{{ implode(', ', $item['detalle']) }}</div>
                                @endif
                                @if ($tipoServicio === 'local')
                                    @php($dest = $item['destino'] ?? 'aqui')
                                    <button type="button" wire:click="alternarDestino('{{ $item['key'] }}')"
                                        style="margin-top:.3rem; font-size:.68rem; padding:.1rem .45rem; border-radius:.35rem; cursor:pointer; border:1px solid {{ $dest === 'llevar' ? '#3b82f6' : 'rgba(128,128,128,.4)' }}; background:{{ $dest === 'llevar' ? '#3b82f6' : 'transparent' }}; color:{{ $dest === 'llevar' ? '#fff' : 'inherit' }};">
                                        {{ $dest === 'llevar' ? '🛍 Llevar' : '🍽 Aquí' }}
                                    </button>
                                @else
                                    <div style="margin-top:.3rem; font-size:.68rem; color:#3b82f6;">🛵 Domicilio</div>
                                @endif
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:.35rem; white-space:nowrap;">
                                <span style="font-weight:700;">L. {{ number_format((float) $item['precio'] * (int) $item['cantidad'], 2) }}</span>
                                <div style="display:flex; align-items:center; gap:.3rem;">
                                    <button type="button" wire:click="cambiarCantidad('{{ $item['key'] }}', -1)"
                                        style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">−</button>
                                    <span style="min-width:1.2rem; text-align:center; font-weight:700;">{{ $item['cantidad'] }}</span>
                                    <button type="button" wire:click="cambiarCantidad('{{ $item['key'] }}', 1)"
                                        style="width:1.4rem; height:1.4rem; border-radius:.3rem; border:none; cursor:pointer; background:rgba(128,128,128,.2); color:inherit; font-size:1rem; line-height:1;">+</button>
                                    <x-filament::icon-button icon="heroicon-o-x-mark" color="danger" wire:click="quitarLinea('{{ $item['key'] }}')" label="Quitar" />
                                </div>
                            </div>
                        </div>
                    @empty
                        <p style="text-align:center; padding:1.5rem 0; opacity:.6; font-size:.9rem;">Aún no hay nada en la venta.</p>
                    @endforelse
                </div>

                <div style="margin-top:1rem; padding-top:.75rem; border-top:1px solid rgba(128,128,128,.25); display:flex; flex-direction:column; gap:.25rem; font-size:.9rem;">
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Exento</span><span>L. {{ number_format($this->resumen['exento'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>Gravado</span><span>L. {{ number_format($this->resumen['gravado'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; opacity:.75;"><span>ISV (15%)</span><span>L. {{ number_format($this->resumen['isv'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; font-size:1.15rem; font-weight:700; padding-top:.25rem;"><span>Total</span><span>L. {{ number_format($this->resumen['total'], 2) }}</span></div>
                </div>

                <div style="display:flex; align-items:center; gap:.4rem; margin-top:.75rem; flex-wrap:wrap;">
                    <span style="font-size:.78rem; opacity:.6;">Pago:</span>
                    @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transf.'] as $fp => $lbl)
                        <x-filament::button size="xs" :color="$formaPago === $fp ? 'primary' : 'gray'" wire:click="$set('formaPago','{{ $fp }}')">{{ $lbl }}</x-filament::button>
                    @endforeach
                </div>

                @if ($formaPago === 'transferencia')
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
                </div>
            </x-filament::section>
        </div>
    </div>

    {{-- Impresión directa de la factura: iframe oculto, sin pestaña nueva --}}
    @script
    <script>
        $wire.on('imprimir-factura', (event) => {
            const url = Array.isArray(event) ? event[0]?.url : event?.url;
            if (! url) return;

            // Reusar un iframe oculto para no acumular nodos.
            let iframe = document.getElementById('factura-print-frame');
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'factura-print-frame';
                iframe.style.position = 'fixed';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                document.body.appendChild(iframe);
            }

            iframe.onload = () => {
                try {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } catch (e) {
                    // Si el navegador bloquea el print del PDF embebido, lo abre.
                    window.open(url, '_blank');
                }
            };

            iframe.src = url;
        });
    </script>
    @endscript

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
                    <x-slot name="description">Total a facturar: L. {{ number_format($this->resumen['total'], 2) }}</x-slot>

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
                                <div style="position:absolute; z-index:60; left:0; right:0; margin-top:.25rem; background:#1b2740; border:1px solid #2b3a59; border-radius:.5rem; overflow:hidden;">
                                    @foreach ($sugerencias as $s)
                                        <button type="button" wire:click="elegirCliente('{{ $s['rtn'] }}', @js($s['nombre']))"
                                            style="display:block; width:100%; text-align:left; padding:.5rem .6rem; background:none; border:none; cursor:pointer; color:#e7ecf3; border-bottom:1px solid rgba(128,128,128,.15);">
                                            <span style="font-weight:600;">{{ $s['nombre'] }}</span>
                                            <span style="display:block; font-size:.72rem; opacity:.7;">RTN: {{ $s['rtn'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <label style="display:flex; align-items:center; gap:.5rem; cursor:pointer; padding:.5rem; border:1px solid rgba(128,128,128,.25); border-radius:.5rem;">
                            <input type="checkbox" wire:model="facturaDetallada" style="width:1.1rem; height:1.1rem;" />
                            <span style="font-size:.85rem;">Detallar productos en la factura<br><span style="font-size:.72rem; opacity:.65;">Si no, se factura como “Alimentación”</span></span>
                        </label>
                        <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.5rem;">
                            <x-filament::button color="gray" wire:click="$set('mostrarFactura', false)">Cancelar</x-filament::button>
                            <x-filament::button color="primary" wire:click="emitirFactura">Emitir factura</x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
