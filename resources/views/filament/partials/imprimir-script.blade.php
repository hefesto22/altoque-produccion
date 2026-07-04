{{--
    Impresión directa desde cualquier pantalla del panel (POS, Ventas…):
    carga la URL (HTML de factura/comanda) en un iframe oculto y dispara
    el print — sin pestañas nuevas ni esperar a Chromium.

    Las impresiones se ENCOLAN: si una venta imprime factura + comanda,
    el segundo diálogo sale al cerrar el primero (dos print() simultáneos
    se pisan y el navegador descarta uno).

    Registrado globalmente vía render hook en AppServiceProvider. Los
    componentes solo hacen $this->dispatch('imprimir-factura'|'imprimir-comanda', url: ...).
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const cola = [];
        let imprimiendo = false;

        const procesar = () => {
            if (imprimiendo || cola.length === 0) return;
            imprimiendo = true;
            const url = cola.shift();

            let iframe = document.getElementById('print-frame');
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'print-frame';
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
                    // print() bloquea hasta cerrar el diálogo: al volver,
                    // seguimos con la siguiente impresión de la cola.
                    iframe.contentWindow.print();
                } catch (e) {
                    // Si el navegador bloquea el print embebido, lo abre.
                    window.open(url, '_blank');
                }
                imprimiendo = false;
                setTimeout(procesar, 250);
            };

            iframe.src = url;
        };

        const encolar = (event) => {
            const url = Array.isArray(event) ? event[0]?.url : event?.url;
            if (! url) return;
            cola.push(url);
            procesar();
        };

        Livewire.on('imprimir-factura', encolar);
        Livewire.on('imprimir-comanda', encolar);
    });
</script>
