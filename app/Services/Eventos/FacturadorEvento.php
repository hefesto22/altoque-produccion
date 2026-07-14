<?php

declare(strict_types=1);

namespace App\Services\Eventos;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\Exceptions\EventoNoFacturableException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\CorteCaja;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\Producto;
use App\Services\Pos\VentaService;

/**
 * Emite LA factura SAR de un evento a partir de su cotización aceptada.
 *
 * Decisiones confirmadas con Mauricio (2026-07-14):
 *  - UNA factura por el total al completar el evento; los abonos previos
 *    son pagos internos sin documento fiscal.
 *  - El cobro entra al corte del turno abierto de quien factura.
 *  - Cero margen de error: guardas duras (estado, doble factura, saldo,
 *    turno) + verificación AL CENTAVO del desglose contra la cotización.
 *
 * No duplica lógica fiscal: construye LineaVenta[] que reproducen el
 * desglose de la cotización y delega en VentaService::registrarFactura
 * (desglose vía CalculadorVenta, corte, correlativo SAR bajo lock,
 * pagos mixtos validados). La venta usa el producto genérico
 * "SERVICIO DE EVENTO" (inactivo: no aparece en POS ni menú) con el
 * nombre de cada ítem congelado como snapshot.
 */
final class FacturadorEvento
{
    public function __construct(
        private readonly VentaService $ventas,
        private readonly CalculaImpuestos $calculador,
    ) {}

    public function facturar(Cotizacion $cotizacion, int $cajeroId): Factura
    {
        $cotizacion->loadMissing('items');

        if ($cotizacion->estado !== 'aceptada') {
            throw new EventoNoFacturableException('Solo se factura una cotización ACEPTADA.');
        }

        if ($cotizacion->venta_id !== null) {
            throw new EventoNoFacturableException('Esta cotización ya fue facturada (no se emite dos veces).');
        }

        if ($cotizacion->items->isEmpty()) {
            throw new EventoNoFacturableException('La cotización no tiene ítems.');
        }

        $saldo = $cotizacion->saldo();

        if (abs($saldo) >= 0.01) {
            throw new EventoNoFacturableException(
                'Lo abonado no cuadra con el total del evento (saldo: L. '.number_format($saldo, 2).'). Registrá el pago del saldo antes de facturar.'
            );
        }

        $turnoAbierto = CorteCaja::query()
            ->where('cajero_id', $cajeroId)
            ->where('estado', 'abierto')
            ->exists();

        if (! $turnoAbierto) {
            throw new EventoNoFacturableException('Abrí tu turno de caja: el cobro del evento entra al corte del turno.');
        }

        $lineas = $this->lineas($cotizacion);

        // Defensa activa: el desglose de las líneas debe reproducir AL
        // CENTAVO el de la cotización que el cliente aceptó. Si difiere
        // en un centavo, NO se emite factura.
        $resumen = $this->calculador->calcular($lineas);

        $esperado = [
            'gravado' => (float) $cotizacion->gravado,
            'exento'  => (float) $cotizacion->exento,
            'isv'     => (float) $cotizacion->isv,
            'total'   => (float) $cotizacion->total,
        ];
        $calculado = [
            'gravado' => $resumen->gravado,
            'exento'  => $resumen->exento,
            'isv'     => $resumen->isv,
            'total'   => $resumen->total,
        ];

        foreach ($esperado as $campo => $valor) {
            if (abs($calculado[$campo] - $valor) >= 0.01) {
                throw new EventoNoFacturableException(
                    "El {$campo} no cuadra con la cotización aceptada (L. ".number_format($calculado[$campo], 2).' vs L. '.number_format($valor, 2).'). No se emitió factura — revisá la cotización.'
                );
            }
        }

        [$formaPago, $banco, $pagos] = $this->pagosParaVenta($cotizacion);

        $rtn = $cotizacion->cliente_rtn !== null && $cotizacion->cliente_rtn !== ''
            ? new RTN($cotizacion->cliente_rtn)
            : null;

        $factura = $this->ventas->registrarFactura(
            lineas: $lineas,
            cajeroId: $cajeroId,
            rtn: $rtn,
            nombre: $cotizacion->cliente_nombre,
            formaPago: $formaPago,
            detallada: true,
            banco: $banco,
            tipoOrden: 'local',
            pagos: $pagos,
            nombreOrden: 'EVENTO '.$cotizacion->numero,
        );

        $cotizacion->forceFill([
            'venta_id' => $factura->venta_id,
            'estado'   => 'completada',
        ])->save();

        return $factura;
    }

    /**
     * Reproduce los ítems de la cotización como líneas de venta, con el
     * MISMO prorrateo de descuento de CotizadorEventos (por peso, el
     * último ítem absorbe el redondeo). Cada línea va con cantidad 1 y
     * el importe neto del ítem, así los montos son exactos aunque la
     * cantidad cotizada tenga decimales.
     *
     * @return array<int, LineaVenta>
     */
    private function lineas(Cotizacion $cotizacion): array
    {
        $productoId = $this->productoEventoId();
        $items = $cotizacion->items->values();

        $subtotal = round($items->sum(static fn ($i): float => $i->importe()), 2);
        $descuento = round(min(max((float) $cotizacion->descuento, 0.0), $subtotal), 2);
        $restante = $descuento;
        $ultimo = $items->count() - 1;

        $lineas = [];

        foreach ($items as $idx => $item) {
            $bruto = $item->importe();

            $desc = $idx === $ultimo || $subtotal <= 0.0
                ? $restante
                : round($descuento * ($bruto / $subtotal), 2);
            $restante = round($restante - $desc, 2);

            $neto = round($bruto - $desc, 2);

            $cantidad = rtrim(rtrim(number_format((float) $item->cantidad, 2, '.', ''), '0'), '.');
            $sufijo = abs((float) $item->cantidad - 1.0) > 0.001 ? " × {$cantidad}" : '';

            $lineas[] = new LineaVenta(
                productoId: $productoId,
                nombre: mb_strtoupper($item->descripcion).$sufijo,
                precioUnitario: $neto,
                cantidad: 1,
                gravaIsv: $item->grava_isv,
                precioListaUnitario: $desc > 0.0 ? $bruto : null,
            );
        }

        return $lineas;
    }

    /**
     * Producto genérico que porta las líneas del evento (venta_items exige
     * producto). Inactivo: no aparece en el POS ni en el menú. El nombre
     * real de cada ítem queda congelado en el snapshot de la línea.
     */
    private function productoEventoId(): int
    {
        return Producto::query()->firstOrCreate(
            ['nombre' => 'SERVICIO DE EVENTO'],
            ['categoria' => 'extra', 'precio' => 0, 'grava_isv' => true, 'activo' => false],
        )->id;
    }

    /**
     * Consolida los abonos en la forma de pago de la venta: un solo
     * método si todos los abonos coinciden; 'mixto' con su detalle si no
     * (normalizarPagos de VentaService valida que cuadren al centavo).
     *
     * @return array{0: string, 1: string|null, 2: array<int, array{metodo: string, banco: string|null, monto: float}>|null}
     */
    private function pagosParaVenta(Cotizacion $cotizacion): array
    {
        $grupos = [];

        foreach ($cotizacion->pagos()->get() as $pago) {
            $clave = $pago->forma_pago.'|'.($pago->banco ?? '');

            $grupos[$clave] ??= ['metodo' => $pago->forma_pago, 'banco' => $pago->banco, 'monto' => 0.0];
            $grupos[$clave]['monto'] = round($grupos[$clave]['monto'] + (float) $pago->monto, 2);
        }

        $grupos = array_values($grupos);

        if (count($grupos) === 1) {
            return [$grupos[0]['metodo'], $grupos[0]['banco'], null];
        }

        return ['mixto', null, $grupos];
    }
}
