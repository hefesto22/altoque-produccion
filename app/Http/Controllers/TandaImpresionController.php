<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Comanda;
use App\Models\Factura;
use App\Models\Impresion;
use App\Services\Facturacion\FacturaPdfService;
use App\Services\Impresion\ColaImpresionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Tanda de impresión: varios tickets en UN SOLO documento.
 *
 * En hora pico la caja puede juntar 20 pendientes. Sacarlos de a uno son 20
 * diálogos de impresión, y cada print() bloquea la pestaña hasta que alguien
 * lo cierre: inmanejable. Acá salen todos juntos, separados por salto de
 * página — un diálogo, un envío, y la térmica corta entre ticket y ticket
 * (mismo mecanismo que el documento factura+comanda que ya se usa a diario).
 */
class TandaImpresionController extends Controller
{
    public function show(Request $request, FacturaPdfService $facturas): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(static fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->take(ColaImpresionService::MAX_TANDA)
            ->all();

        $impresiones = Impresion::query()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get();

        $bloques = [];

        foreach ($impresiones as $impresion) {
            foreach ($this->bloquesDe($impresion, $facturas) as $bloque) {
                $bloques[] = $bloque;
            }
        }

        // Sin nada que imprimir no se manda una hoja en blanco a la térmica.
        abort_if($bloques === [], 404);

        return view('tickets.tanda', ['bloques' => $bloques]);
    }

    /**
     * Un trabajo puede rendir dos páginas: 'documentos' es factura + comanda.
     *
     * @return array<int, array{tipo: string, datos: array<string, mixed>}>
     */
    private function bloquesDe(Impresion $impresion, FacturaPdfService $facturas): array
    {
        if ($impresion->tipo === 'comanda') {
            $comanda = Comanda::find($impresion->referencia_id);

            return $comanda === null ? [] : [['tipo' => 'comanda', 'datos' => ['comanda' => $comanda]]];
        }

        // El corte de caja no entra en tandas: arma sus datos con consultas
        // propias y sale una vez al día, así que se imprime solo.
        if ($impresion->tipo !== 'factura' && $impresion->tipo !== 'documentos') {
            return [];
        }

        $factura = Factura::find($impresion->referencia_id);

        if ($factura === null) {
            return [];
        }

        $bloques = [['tipo' => 'factura', 'datos' => $facturas->datosVista($factura)]];

        $comanda = $impresion->tipo === 'documentos' ? $factura->venta?->comanda : null;

        if ($comanda !== null) {
            $bloques[] = ['tipo' => 'comanda', 'datos' => ['comanda' => $comanda]];
        }

        return $bloques;
    }
}
