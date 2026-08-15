{{--
    Cola de impresión del POS.

    Hay una sola térmica y está en esta computadora. Lo que se pide desde las
    tablets del salón cae acá; el papel que sale de este panel es el ÚNICO
    canal hacia la cocina (no hay pantalla allá).

    Diseñado para hora pica, no para el caso de un ticket: los botones de
    tanda sacan TODO lo pendiente de su tipo en un solo documento (un diálogo,
    un envío). Comandas y facturas van separadas porque terminan en lugares
    distintos — cocina y cliente — y nadie tiene tiempo de separar veinte
    tickets mezclados en plena caja. La lista detallada se despliega solo si
    hace falta; con 20 pendientes taparía el POS entero.

    Poll propio (4s en la caja, 10s en las tablets) para no arrastrar el
    re-render del POS completo.
--}}
<div @if ($puedeImprimir) wire:poll.4s @else wire:poll.10s @endif>

    {{-- ─────────── LA CAJA: tandas + lista ─────────── --}}
    @if ($puedeImprimir && $conteo['total'] > 0)
        @php($masViejo = (int) ($pendientes->first()?->created_at?->diffInMinutes() ?? 0))
        <div style="margin:0 0 1rem; padding:.85rem 1rem; border-radius:.6rem;
                    border:2px solid {{ $masViejo >= 5 ? '#ef4444' : '#f59e0b' }};
                    border-left:6px solid {{ $masViejo >= 5 ? '#ef4444' : '#f59e0b' }};
                    background:{{ $masViejo >= 5 ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.12)' }};">

            <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <div style="flex:1; min-width:15rem;">
                    <div style="font-weight:800; font-size:1.15rem;">
                        🖨️ {{ $conteo['total'] }} pendiente(s) de imprimir
                        @if ($masViejo >= 3)
                            <span style="color:#ef4444;">· el más viejo hace {{ $masViejo }} min</span>
                        @endif
                    </div>
                    <div style="font-weight:600; font-size:.76rem; opacity:.8;">
                        Cada tanda sale en un solo papel corrido — la comanda va a cocina, la factura al cliente.
                    </div>
                </div>

                {{-- Tandas: un clic saca todo lo de ese tipo. --}}
                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                    @if ($conteo['comanda'] > 0)
                        <x-filament::button color="warning" wire:click="imprimirTanda('comanda')"
                            wire:loading.attr="disabled" wire:target="imprimirTanda">
                            Comandas ({{ min($conteo['comanda'], $tope) }})
                        </x-filament::button>
                    @endif

                    @if ($conteo['factura'] > 0)
                        <x-filament::button color="info" wire:click="imprimirTanda('factura')"
                            wire:loading.attr="disabled" wire:target="imprimirTanda">
                            Facturas ({{ min($conteo['factura'], $tope) }})
                        </x-filament::button>
                    @endif

                    @if ($conteo['comanda'] > 0 && $conteo['factura'] > 0)
                        <x-filament::button color="success" wire:click="imprimirTanda('todo')"
                            wire:loading.attr="disabled" wire:target="imprimirTanda">
                            Todo ({{ min($conteo['todo'], $tope) }})
                        </x-filament::button>
                    @endif
                </div>
            </div>

            @if ($conteo['todo'] > $tope)
                <div style="margin-top:.5rem; font-size:.74rem; font-weight:700; color:#ef4444;">
                    Se sacan {{ $tope }} por tanda; el resto queda en la cola para la siguiente.
                </div>
            @endif

            {{-- Lista detallada: a la vista si son pocas, plegada si son muchas. --}}
            @php($listaAbierta = $mostrarLista || $conteo['total'] <= 3)

            @if ($conteo['total'] > 3)
                <button type="button" wire:click="$toggle('mostrarLista')"
                    style="background:none; border:0; padding:.45rem 0 0; cursor:pointer;
                           font-size:.75rem; font-weight:700; opacity:.7;">
                    {{ $listaAbierta ? 'Ocultar el detalle ▲' : 'Ver uno por uno ▼' }}
                </button>
            @endif

            @if ($listaAbierta)
                {{-- max-height: con 40 pendientes esto no puede empujar el menú
                     fuera de la pantalla. --}}
                <div style="display:flex; flex-direction:column; gap:.4rem; margin-top:.6rem;
                            max-height:15rem; overflow-y:auto;">
                    @foreach ($pendientes as $p)
                        @php($minutos = (int) $p->created_at->diffInMinutes())
                        <div style="border:1.5px solid {{ $minutos >= 3 ? '#ef4444' : '#f59e0b' }}; border-radius:.5rem;
                                    padding:.45rem .7rem; background:rgba(0,0,0,.14);
                                    display:flex; flex-wrap:wrap; align-items:center; gap:.6rem;">

                            <div style="flex:1 1 14rem; min-width:11rem;">
                                <span style="font-weight:800;">{{ $p->etiqueta }}</span>
                                <span style="font-size:.7rem; font-weight:700; opacity:.7;">· {{ $p->tipoLabel() }}</span>
                                @if ($p->detalle)
                                    <div style="font-size:.72rem; opacity:.75;">{{ $p->detalle }}</div>
                                @endif
                            </div>

                            <span style="font-size:.72rem; font-weight:700;
                                         @if ($minutos >= 3) color:#ef4444; @else opacity:.6; @endif">
                                {{ $minutos < 1 ? 'recién' : 'hace '.$minutos.' min' }}
                            </span>

                            <div style="display:flex; gap:.3rem;">
                                <x-filament::button size="xs" color="success"
                                    wire:click="imprimir({{ $p->id }})"
                                    wire:loading.attr="disabled" wire:target="imprimir">
                                    Imprimir
                                </x-filament::button>
                                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="cancelar({{ $p->id }})">
                                    Descartar
                                </x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ─────────── Reimprimir: la vía de rescate ───────────
         Si el papel se atasca, si alguien cancela el diálogo a mitad de una
         tanda, o si alguien de caja entró desde una tablet y el trabajo se
         marcó impreso sin salir, esto es lo único que lo salva. Por eso la
         entrada está siempre a la vista, aunque la lista arranque plegada. --}}
    @if ($puedeImprimir && $recientes->isNotEmpty())
        <div style="margin:0 0 1rem;">
            <button type="button" wire:click="$toggle('mostrarRecientes')"
                style="background:none; border:0; padding:.15rem 0; cursor:pointer;
                       font-size:.75rem; font-weight:700; opacity:.6;">
                🖨️ Reimprimir… ({{ $recientes->count() }} de las últimas 2 h) {{ $mostrarRecientes ? '▲' : '▼' }}
            </button>

            @if ($mostrarRecientes)
                <div style="margin-top:.5rem;">
                    <x-filament::button size="xs" color="gray" wire:click="reimprimirTanda"
                        wire:loading.attr="disabled" wire:target="reimprimirTanda">
                        Reimprimir todo esto de un saque
                    </x-filament::button>
                </div>

                <div style="display:flex; flex-direction:column; gap:.35rem; margin-top:.5rem;
                            max-height:13rem; overflow-y:auto;">
                    @foreach ($recientes as $r)
                        <div style="border:1px solid rgba(148,163,184,.35); border-radius:.5rem;
                                    padding:.4rem .7rem;
                                    display:flex; flex-wrap:wrap; align-items:center; gap:.6rem;">
                            <div style="flex:1 1 14rem; min-width:11rem; font-size:.8rem; font-weight:700;">
                                {{ $r->etiqueta }}
                                <span style="font-weight:600; opacity:.6;">· {{ $r->tipoLabel() }}</span>
                                @if ($r->detalle)
                                    <div style="font-size:.72rem; font-weight:600; opacity:.7;">{{ $r->detalle }}</div>
                                @endif
                            </div>
                            <span style="font-size:.72rem; opacity:.55;">{{ $r->impreso_at?->format('h:i a') }}</span>
                            @if ($r->esCombinada())
                                {{-- Factura + comanda: el papel de cada una termina en un
                                     lugar distinto (el cliente y la cocina), así que se
                                     puede sacar solo la que falta. Pedido del cliente. --}}
                                <div style="display:flex; gap:.3rem; flex-wrap:wrap;">
                                    <x-filament::button size="xs" color="gray" outlined
                                        wire:click="reimprimirParte({{ $r->id }}, 'factura')">
                                        Solo factura
                                    </x-filament::button>
                                    <x-filament::button size="xs" color="warning" outlined
                                        wire:click="reimprimirParte({{ $r->id }}, 'comanda')">
                                        Solo comanda
                                    </x-filament::button>
                                    <x-filament::button size="xs" color="gray" outlined
                                        wire:click="reimprimirParte({{ $r->id }}, 'ambas')">
                                        Ambas
                                    </x-filament::button>
                                </div>
                            @else
                                <x-filament::button size="xs" color="gray" outlined
                                    wire:click="reimprimir({{ $r->id }})">
                                    Reimprimir
                                </x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ─────────── LA TABLET: solo enterarse ─────────── --}}
    @if (! $puedeImprimir && $conteo['total'] > 0)
        <div style="margin:0 0 1rem; padding:.6rem .9rem;
                    border:1px solid rgba(148,163,184,.4); border-radius:.6rem;
                    font-size:.8rem; font-weight:700; opacity:.8;">
            🖨️ {{ $conteo['total'] }} pedido(s) esperando impresión en caja.
        </div>
    @endif
</div>
