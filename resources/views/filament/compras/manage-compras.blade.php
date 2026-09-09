{{-- La página estándar del panel, con el desglose diario encima de la tabla. --}}
<x-filament-panels::page>
    @include('filament.compras.desglose-diario')

    {{ $this->content }}
</x-filament-panels::page>
