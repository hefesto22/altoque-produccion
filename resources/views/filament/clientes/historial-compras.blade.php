{{--
    Envoltorio del modal "Historial de compras": monta el componente
    Livewire que pagina las facturas en servidor. El key por cliente
    garantiza un componente fresco al abrir el historial de otro cliente.
--}}
@livewire('historial-cliente', ['cliente' => $cliente], key('historial-cliente-'.$cliente->id))
