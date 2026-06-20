<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- Selección de período --}}
        <x-filament::section>
            <x-slot name="heading">Período fiscal a declarar</x-slot>
            <x-slot name="description">El mes en curso se puede cargar para preview, pero no se declara hasta que termine.</x-slot>

            <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem;">
                <div>
                    <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Año</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="anio">
                            @foreach ($this->anios as $a)
                                <option value="{{ $a }}">{{ $a }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label style="display:block; font-size:.8rem; font-weight:600; margin-bottom:.25rem;">Mes</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="mes">
                            @foreach ($this->meses as $num => $nombre)
                                <option value="{{ $num }}">{{ ucfirst($nombre) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button wire:click="cargar" icon="heroicon-o-arrow-down-tray">
                    Cargar período
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- Resultado --}}
        @if ($resumen !== null)
            <x-filament::section>
                <x-slot name="heading">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                        <span>Totales del período</span>
                        <x-filament::badge :color="$estadoPeriodo === 'declarado' ? 'success' : 'gray'">
                            {{ $estadoPeriodo === 'declarado' ? 'Declarado' : 'Abierto' }}
                        </x-filament::badge>
                    </div>
                </x-slot>

                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem;" class="sm:!grid-cols-4">
                    <div>
                        <div style="font-size:.75rem; opacity:.7;">Ventas</div>
                        <div style="font-size:1.35rem; font-weight:700;">{{ $resumen['cantidad'] }}</div>
                    </div>
                    <div>
                        <div style="font-size:.75rem; opacity:.7;">Gravado</div>
                        <div style="font-size:1.35rem; font-weight:700;">L. {{ number_format($resumen['gravado'], 2) }}</div>
                    </div>
                    <div>
                        <div style="font-size:.75rem; opacity:.7;">Exento</div>
                        <div style="font-size:1.35rem; font-weight:700;">L. {{ number_format($resumen['exento'], 2) }}</div>
                    </div>
                    <div>
                        <div style="font-size:.75rem; opacity:.7; color:rgb(217 119 6);">ISV (débito fiscal)</div>
                        <div style="font-size:1.35rem; font-weight:700; color:rgb(217 119 6);">L. {{ number_format($resumen['isv'], 2) }}</div>
                    </div>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:1rem; margin-top:1rem; padding-top:.75rem; border-top:1px solid rgba(128,128,128,.25); font-size:.9rem;">
                    <span>Recibos: <strong>L. {{ number_format($resumen['recibos'], 2) }}</strong></span>
                    <span>Facturas: <strong>L. {{ number_format($resumen['facturas'], 2) }}</strong></span>
                    <span style="margin-left:auto; font-size:1rem;">Total vendido: <strong>L. {{ number_format($resumen['total'], 2) }}</strong></span>
                </div>

                {{-- Liquidación del ISV: débito − crédito = neto a pagar --}}
                <div style="margin-top:1rem; padding:.85rem; border-radius:.6rem; background:rgba(128,128,128,.08); display:flex; flex-direction:column; gap:.3rem;">
                    <div style="display:flex; justify-content:space-between;"><span>ISV débito (ventas)</span><span>L. {{ number_format($resumen['isv'], 2) }}</span></div>
                    <div style="display:flex; justify-content:space-between; color:#10b981;"><span>− ISV crédito (compras)</span><span>L. {{ number_format($resumen['credito'], 2) }}</span></div>
                    @php($aPagar = $resumen['a_pagar'])
                    <div style="display:flex; justify-content:space-between; font-size:1.2rem; font-weight:800; border-top:1px solid rgba(128,128,128,.25); padding-top:.3rem;">
                        <span>{{ $aPagar >= 0 ? 'ISV a pagar' : 'Saldo a favor' }}</span>
                        <span style="color:{{ $aPagar >= 0 ? '#f59e0b' : '#10b981' }};">L. {{ number_format(abs($aPagar), 2) }}</span>
                    </div>
                </div>

                <div style="margin-top:1.25rem;">
                    @if ($estadoPeriodo === 'declarado')
                        <x-filament::button wire:click="reabrir" color="warning" outlined icon="heroicon-o-lock-open">
                            Reabrir período (rectificativa)
                        </x-filament::button>
                    @elseif ($this->mesEnCurso())
                        <p style="font-size:.9rem; opacity:.7;">El mes en curso no se puede declarar hasta que termine. Esto es solo preview.</p>
                    @else
                        <x-filament::button wire:click="declarar" color="success" icon="heroicon-o-check-badge">
                            Declarar al SAR (cerrar período)
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
