{{--
    Impresión directa desde cualquier pantalla del panel (POS, Ventas…):
    carga la URL (HTML de factura/comanda) en un iframe oculto y dispara
    el print — sin pestañas nuevas ni esperar a Chromium.

    Registrado globalmente vía render hook en AppServiceProvider. Los
    componentes solo hacen $this->dispatch('imprimir-factura'|'imprimir-comanda', url: ...).
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const imprimirUrl = (event, frameId) => {
            const url = Array.isArray(event) ? event[0]?.url : event?.url;
            if (! url) return;

            // Reusar un iframe oculto por tipo para no acumular nodos.
            let iframe = document.getElementById(frameId);
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = frameId;
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
                    // Si el navegador bloquea el print embebido, lo abre.
                    window.open(url, '_blank');
                }
            };

            iframe.src = url;
        };

        Livewire.on('imprimir-factura', (event) => imprimirUrl(event, 'factura-print-frame'));
        Livewire.on('imprimir-comanda', (event) => imprimirUrl(event, 'comanda-print-frame'));
    });
</script>
