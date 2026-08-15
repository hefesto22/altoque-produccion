<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Impresion;
use App\Services\Impresion\ColaImpresionService;
use App\Support\Acceso;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Panel "pendientes de imprimir" del Punto de Venta.
 *
 * Hay una sola térmica y está en la computadora de la caja. Los pedidos que
 * entran desde las tablets del salón no pueden imprimir allá, así que quedan
 * acá esperando; ese papel es lo único que llega a la cocina (no hay pantalla).
 *
 * En hora pico se juntan decenas, así que NO se imprime de a uno: los botones
 * de tanda reclaman todo lo pendiente de su tipo y lo mandan como UN solo
 * documento con salto de página entre tickets — un diálogo, un envío. Comandas
 * y facturas van en tandas separadas porque el papel de cada una termina en un
 * lugar distinto: la cocina y el cliente. Nadie tiene que ponerse a separar
 * veinte tickets mezclados en plena caja.
 *
 * Va montado dentro del POS con @livewire('cola-impresion'): componente aparte
 * para que su poll no arrastre el re-render del POS entero (~212 KB por
 * request) cada pocos segundos.
 */
class ColaImpresion extends Component
{
    /** El bloque de reimpresión arranca cerrado: se abre cuando hace falta. */
    public bool $mostrarRecientes = false;

    /** La lista detallada se despliega; con 20 pendientes taparía el POS. */
    public bool $mostrarLista = false;

    /**
     * Saca de un golpe todo lo pendiente de un tipo: 'comanda', 'factura'
     * (incluye el documento factura+comanda) o 'todo'.
     */
    public function imprimirTanda(string $filtro): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        $cola = app(ColaImpresionService::class);
        $ganados = $cola->reclamarTanda($filtro);

        if ($ganados === []) {
            Notification::make()->title('No quedaba nada por imprimir')->warning()->seconds(3)->send();

            return;
        }

        $this->dispatch('imprimir-factura', url: $cola->urlTanda($ganados));

        $quedan = $cola->contarPendientes();

        Notification::make()
            ->title(count($ganados).' ticket(s) a impresión')
            ->body($quedan > 0 ? "Quedan {$quedan} en la cola." : 'La cola quedó vacía.')
            ->success()
            ->seconds(3)->send();
    }

    /** Saca un solo trabajo (el que se coló, el urgente, el corte de caja). */
    public function imprimir(int $id): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        $impresion = app(ColaImpresionService::class)->reclamar($id);

        // null = otra pestaña (u otra caja) se lo llevó primero.
        if ($impresion === null) {
            Notification::make()->title('Ese trabajo ya lo tomó alguien más')->warning()->seconds(3)->send();

            return;
        }

        $this->despachar($impresion);
    }

    /** Descarta un pendiente sin imprimirlo (pedido anulado, duplicado). */
    public function cancelar(int $id): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        if (app(ColaImpresionService::class)->cancelar($id)) {
            Notification::make()->title('Trabajo descartado')->warning()->seconds(3)->send();
        }
    }

    /**
     * Vuelve a sacar un ticket ya impreso: papel atascado, o alguien de caja
     * que entró desde una tablet y el trabajo se marcó impreso sin salir. NO
     * toca el estado — se conserva quién lo imprimió la primera vez.
     */
    public function reimprimir(int $id): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        $impresion = Impresion::find($id);

        if ($impresion !== null) {
            $this->despachar($impresion);
        }
    }

    /**
     * Reimprime SOLO una parte del documento combinado (factura + comanda).
     *
     * Va solo en la lista de recientes, no en la de pendientes: un pendiente
     * se marca impreso al reclamarlo, así que sacar media orden dejaría la
     * otra mitad marcada como impresa y sin papel — y una comanda que no sale
     * es un pedido perdido en cocina. Acá no se toca ningún estado.
     */
    public function reimprimirParte(int $id, string $parte): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        // Lista blanca: el nombre de la parte viene del navegador.
        if (! in_array($parte, ['factura', 'comanda', 'ambas'], true)) {
            $parte = 'ambas';
        }

        $impresion = Impresion::find($id);

        if ($impresion === null) {
            return;
        }

        $url = $impresion->urlParte($parte);

        if ($url === null) {
            Notification::make()
                ->title($parte === 'comanda' ? 'Esa orden no tiene comanda' : 'Ese documento ya no existe')
                ->body($impresion->etiqueta)
                ->danger()
                ->seconds(4)->send();

            return;
        }

        $this->dispatch('imprimir-factura', url: $url);
    }

    /**
     * Vuelve a sacar TODA la lista de recientes en un solo documento. Es el
     * rescate cuando alguien cancela el diálogo a mitad de una tanda: los
     * trabajos ya quedaron marcados impresos y de a uno serían veinte clics.
     */
    public function reimprimirTanda(): void
    {
        abort_unless(Acceso::puede('ImprimirDirecto'), 403);

        $cola = app(ColaImpresionService::class);

        $ids = $cola->recientes()
            ->filter(static fn (Impresion $i): bool => $i->tipo !== 'corte')
            ->sortBy('impreso_at')
            ->map(static fn (Impresion $i): int => (int) $i->id)
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()->title('No hay nada reciente que reimprimir')->warning()->seconds(3)->send();

            return;
        }

        $this->dispatch('imprimir-factura', url: $cola->urlTanda($ids));

        Notification::make()->title(count($ids).' ticket(s) a reimpresión')->success()->seconds(3)->send();
    }

    public function render(): View
    {
        $cola = app(ColaImpresionService::class);
        $puedeImprimir = Acceso::puede('ImprimirDirecto');

        return view('livewire.cola-impresion', [
            'puedeImprimir' => $puedeImprimir,
            'conteo'        => $cola->conteoPendientes(),
            'pendientes'    => $puedeImprimir ? $cola->pendientes() : new Collection,
            'recientes'     => $puedeImprimir ? $cola->recientes() : new Collection,
            'tope'          => ColaImpresionService::MAX_TANDA,
        ]);
    }

    /**
     * Manda la URL a la cola de impresión del navegador. El script global de
     * `panels::body.end` escucha `imprimir-factura` desde cualquier
     * componente: el evento burbujea hasta window.
     */
    private function despachar(Impresion $impresion): bool
    {
        $url = $impresion->url();

        // El documento se borró entre que se encoló y ahora: sin esto el
        // iframe cargaría un 404 y lo mandaría a la térmica.
        if ($url === null) {
            Notification::make()
                ->title('Ese documento ya no existe')
                ->body($impresion->etiqueta)
                ->danger()
                ->seconds(3)->send();

            return false;
        }

        $this->dispatch('imprimir-factura', url: $url);

        return true;
    }
}
