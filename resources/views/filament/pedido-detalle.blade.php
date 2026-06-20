<div style="display:flex; flex-direction:column; gap:.5rem; font-size:.9rem;">
    <div><strong>Cliente:</strong> {{ $pedido->cliente_nombre }} · 📞 {{ $pedido->cliente_telefono }}</div>
    @if ($pedido->cliente_identidad)<div><strong>Identidad:</strong> {{ $pedido->cliente_identidad }}</div>@endif
    @if ($pedido->esDomicilio())<div><strong>📍 Dirección:</strong> {{ $pedido->cliente_direccion }}</div>@endif
    <div><strong>Tipo:</strong> {{ $pedido->esDomicilio() ? 'Domicilio' : 'Retiro' }} · <strong>Pago:</strong> {{ ucfirst($pedido->metodo_pago) }}</div>
    @if ($pedido->notas)<div><strong>Notas:</strong> {{ $pedido->notas }}</div>@endif

    <div style="border-top:1px solid rgba(128,128,128,.25); margin-top:.4rem; padding-top:.4rem;">
        <strong>Pedido:</strong>
        <ul style="margin:.3rem 0; padding-left:1.2rem;">
            @foreach ($pedido->items as $item)
                <li>
                    {{ $item['cantidad'] }}× {{ $item['nombre'] }} — L. {{ number_format((float) $item['precio'] * (int) $item['cantidad'], 2) }}
                    @if (! empty($item['detalle']))
                        <span style="display:block; font-size:.75rem; opacity:.7;">{{ implode(', ', $item['detalle']) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <div style="text-align:right; font-weight:700;">Total: L. {{ number_format((float) $pedido->total, 2) }}</div>
    </div>
</div>
