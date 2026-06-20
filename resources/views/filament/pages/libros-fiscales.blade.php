<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Seleccionar período</x-slot>
        <x-slot name="description">Los libros se generan on-demand. No requieren que el período esté declarado.</x-slot>

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
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1.25rem; padding-top:1rem; border-top:1px solid rgba(128,128,128,.25);">
            <x-filament::button wire:click="descargarLibroVentas" icon="heroicon-o-arrow-down-tray">
                Libro de Ventas (facturas SAR)
            </x-filament::button>
            <x-filament::button wire:click="descargarLibroCompras" color="success" icon="heroicon-o-arrow-down-tray">
                Libro de Compras (crédito fiscal)
            </x-filament::button>
            <x-filament::button wire:click="descargarReporteContador" color="gray" outlined icon="heroicon-o-arrow-down-tray">
                Reporte del contador (todas las ventas)
            </x-filament::button>
        </div>

        <div style="margin-top:1.25rem; font-size:.9rem; opacity:.85; line-height:1.5;">
            <p><strong>Libro de Ventas:</strong> solo facturas (con CAI) emitidas en el período — el libro formal del SAR.</p>
            <p><strong>Reporte del contador:</strong> todas las ventas (recibo y factura) con su desglose, para declarar manualmente.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
