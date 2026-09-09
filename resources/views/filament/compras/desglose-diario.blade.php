{{--
    Desglose diario de compras.

    El contador lleva el control día por día ("el lunes fueron L. 3,400, el
    martes L. 1,200"), así que arriba de la tabla va una tarjeta por cada día
    del mes con movimiento. Tocar una tarjeta enciende el filtro `dia` de la
    tabla de abajo: el desglose y la lista siempre dicen lo mismo.
--}}
@php
    $dias = $this->desglose;
    $totales = $this->totalesDe($dias);
    $diaActivo = $this->diaFiltrado();
@endphp

<x-filament::section
    icon="heroicon-o-calendar-days"
    collapsible
    persist-collapsed
    collapse-id="desglose-compras"
>
    <x-slot name="heading">Gasto por día</x-slot>
    <x-slot name="description">Tocá un día para ver solo esas compras. Tocalo otra vez para volver a todo el mes.</x-slot>

    {{-- Mes que se está viendo + totales de ese mes --}}
    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:.75rem 1rem; margin-bottom:1rem;">
        <div style="display:flex; align-items:center; gap:.25rem;">
            <x-filament::icon-button
                icon="heroicon-m-chevron-left"
                color="gray"
                label="Mes anterior"
                wire:click="mesAnterior"
            />
            <span style="min-width:10.5rem; text-align:center; font-weight:700;">{{ $this->tituloMes() }}</span>
            <x-filament::icon-button
                icon="heroicon-m-chevron-right"
                color="gray"
                label="Mes siguiente"
                :disabled="$this->esMesActual()"
                wire:click="mesSiguiente"
            />
        </div>

        <div style="display:flex; align-items:center; gap:.5rem;">
            <span style="font-size:.8rem; opacity:.75; white-space:nowrap;">Ir a un día</span>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="date"
                    wire:model.live="diaCalendario"
                    max="{{ now()->toDateString() }}"
                />
            </x-filament::input.wrapper>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:1.25rem; margin-left:auto; font-size:.9rem; align-items:baseline;">
            <span style="opacity:.8;">{{ $totales['compras'] }} {{ $totales['compras'] === 1 ? 'compra' : 'compras' }} en {{ $totales['dias'] }} {{ $totales['dias'] === 1 ? 'día' : 'días' }}</span>
            <span>ISV acreditable: <strong style="color:#10b981;">L. {{ number_format($totales['isv'], 2) }}</strong></span>
            <span>Total del mes: <strong style="font-size:1.1rem;">L. {{ number_format($totales['total'], 2) }}</strong></span>
        </div>
    </div>

    @if ($dias === [])
        <p style="font-size:.9rem; opacity:.7;">No hay compras registradas en {{ $this->tituloMes() }}. Usá <strong>&lsaquo;</strong> para ver meses anteriores.</p>
    @else
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(9.5rem, 1fr)); gap:.6rem;">
            @foreach ($dias as $d)
                @php($activo = $diaActivo === $d['fecha'])
                <button
                    type="button"
                    wire:key="dia-{{ $d['fecha'] }}"
                    wire:click="verDia('{{ $d['fecha'] }}')"
                    title="Ver las compras del {{ $d['largo'] }}"
                    style="
                        text-align:left; cursor:pointer; padding:.6rem .7rem; border-radius:.7rem;
                        border:1px solid {{ $activo ? 'var(--primary-500)' : 'rgba(128,128,128,.25)' }};
                        background:{{ $activo ? 'color-mix(in oklab, var(--primary-500) 14%, transparent)' : 'rgba(128,128,128,.06)' }};
                        box-shadow:{{ $activo ? '0 0 0 1px var(--primary-500)' : 'none' }};
                        transition:background .15s, border-color .15s;
                    "
                >
                    <div style="display:flex; align-items:baseline; gap:.35rem;">
                        <span style="font-size:1.15rem; font-weight:800; line-height:1;">{{ $d['dia'] }}</span>
                        <span style="font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65;">{{ $d['semana'] }}</span>
                        @if ($d['hoy'])
                            <span style="margin-left:auto; font-size:.65rem; font-weight:700; text-transform:uppercase; opacity:.85;">Hoy</span>
                        @endif
                    </div>

                    <div style="font-size:1.05rem; font-weight:700; margin-top:.4rem; line-height:1.15;">L. {{ number_format($d['total'], 2) }}</div>

                    <div style="font-size:.75rem; opacity:.7; margin-top:.15rem;">
                        {{ $d['compras'] }} {{ $d['compras'] === 1 ? 'compra' : 'compras' }}
                        @if ($d['isv'] > 0)
                            <span style="color:#10b981;">· ISV {{ number_format($d['isv'], 2) }}</span>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>

        @if ($diaActivo !== null)
            <div style="margin-top:.9rem;">
                <x-filament::button
                    size="sm"
                    color="gray"
                    outlined
                    icon="heroicon-m-x-mark"
                    wire:click="verTodoElMes"
                >
                    Ver todos los días del mes
                </x-filament::button>
            </div>
        @endif
    @endif
</x-filament::section>
