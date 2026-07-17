<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Cliente;
use App\Models\Factura;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Historial de compras de un cliente (modal lateral en Clientes).
 *
 * Paginado en servidor pensando en volumen: cada página trae solo 25
 * facturas (sobre el índice rtn_cliente + emitida_at). Los totales del
 * resumen se agregan en SQL UNA vez al abrir, y los subtotales por mes
 * salen de una consulta agregada aparte — siempre son el total real del
 * mes aunque el mes quede partido entre páginas.
 *
 * Solo guarda el RTN (no el modelo): es lo único que las consultas
 * necesitan y evita serializar el Cliente en el estado Livewire.
 */
class HistorialCliente extends Component
{
    private const POR_PAGINA = 25;

    public string $rtn = '';

    public int $pagina = 1;

    /** Resumen calculado una sola vez en mount (no cambia mientras el modal está abierto). */
    public int $compras = 0;

    public float $total = 0.0;

    public ?string $ultima = null;

    public int $totalRegistros = 0;

    /** Factura abierta en el visor inline (null = se muestra la lista). */
    public ?int $facturaVista = null;

    public string $facturaVistaNumero = '';

    public string $facturaVistaUrl = '';

    public function mount(Cliente $cliente): void
    {
        $this->rtn = $cliente->rtn;

        $stats = Factura::query()
            ->where('rtn_cliente', $this->rtn)
            ->where('anulada', false)
            ->toBase()
            ->selectRaw('COUNT(*) AS compras, COALESCE(SUM(total), 0) AS total_comprado, MAX(emitida_at) AS ultima_compra')
            ->first();

        $this->compras = (int) ($stats->compras ?? 0);
        $this->total = (float) ($stats->total_comprado ?? 0);
        $this->ultima = $stats !== null && $stats->ultima_compra !== null
            ? Carbon::parse((string) $stats->ultima_compra)->format('d/m/Y')
            : null;

        $this->totalRegistros = Factura::query()
            ->where('rtn_cliente', $this->rtn)
            ->count();
    }

    public function paginas(): int
    {
        return max(1, (int) ceil($this->totalRegistros / self::POR_PAGINA));
    }

    public function anterior(): void
    {
        $this->pagina = max(1, $this->pagina - 1);
    }

    public function siguiente(): void
    {
        $this->pagina = min($this->paginas(), $this->pagina + 1);
    }

    /**
     * Abre una factura en el visor inline del modal (sin cambiar de
     * pestaña): el iframe carga el HTML instantáneo del ticket — el mismo
     * que usa la impresión de caja, sin pasar por Chromium. El scope por
     * RTN evita ver facturas de otro cliente manipulando el id.
     */
    public function ver(int $facturaId): void
    {
        $factura = Factura::query()
            ->where('rtn_cliente', $this->rtn)
            ->findOrFail($facturaId);

        $this->facturaVista = $factura->id;
        $this->facturaVistaNumero = $factura->numero;
        $this->facturaVistaUrl = $factura->urlTicket();
    }

    /** Vuelve del visor a la lista (conserva la página en la que estaba). */
    public function cerrarVisor(): void
    {
        $this->facturaVista = null;
        $this->facturaVistaNumero = '';
        $this->facturaVistaUrl = '';
    }

    public function render(): View
    {
        $facturas = Factura::query()
            ->where('rtn_cliente', $this->rtn)
            ->orderByDesc('emitida_at')
            ->orderByDesc('id')
            ->offset(($this->pagina - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA)
            ->get(['id', 'numero', 'forma_pago', 'total', 'anulada', 'emitida_at']);

        // Subtotales reales de los meses visibles en esta página.
        $meses = $facturas
            ->map(static fn (Factura $f): string => $f->emitida_at->format('Y-m'))
            ->unique()
            ->values();

        $totalesMes = $meses->isEmpty() ? collect() : Factura::query()
            ->where('rtn_cliente', $this->rtn)
            ->where('anulada', false)
            ->whereIn(DB::raw("to_char(emitida_at, 'YYYY-MM')"), $meses->all())
            ->toBase()
            ->selectRaw("to_char(emitida_at, 'YYYY-MM') AS mes, COALESCE(SUM(total), 0) AS total")
            ->groupBy('mes')
            ->pluck('total', 'mes');

        return view('livewire.historial-cliente', [
            'facturas'   => $facturas,
            'totalesMes' => $totalesMes,
            'paginas'    => $this->paginas(),
        ]);
    }
}
