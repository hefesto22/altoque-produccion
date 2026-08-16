<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Domain\Exceptions\FacturaNoAnulableException;
use App\Domain\Exceptions\RangoCaiAgotadoException;
use App\Domain\Exceptions\SinCaiActivoException;
use App\Domain\ValueObjects\RTN;
use App\Events\FacturaEmitida;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\CuentaMovimiento;
use App\Models\CuentaPrepago;
use App\Models\EmpresaSetting;
use App\Models\Factura;
use App\Models\PeriodoFiscal;
use App\Models\Venta;
use App\Services\Cuentas\CuentaPrepagoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emisión de factura SAR. Aquí vive LA race condition financiera del
 * sistema: dos cajas emitiendo a la vez no pueden recibir el mismo
 * correlativo.
 *
 * Garantía de unicidad en dos capas:
 *   1. lockForUpdate() sobre el CAI activo dentro de la transacción.
 *   2. constraint única (cai_id, correlativo) en la BD.
 *
 * Si no hay CAI activo se lanza SinCaiActivoException — la caja puede
 * seguir registrando recibos (decisión confirmada: no se bloquea la
 * operación, solo la emisión fiscal).
 */
final class FacturacionSarService
{
    public function __construct(
        private readonly CuentaPrepagoService $cuentas,
    ) {}

    /**
     * @throws SinCaiActivoException
     * @throws RangoCaiAgotadoException
     */
    public function emitirFactura(Venta $venta, ?RTN $rtn, string $nombreCliente, ?bool $detallada = null): Factura
    {
        return DB::transaction(function () use ($venta, $rtn, $nombreCliente, $detallada): Factura {
            // Bloquea el rango CAI activo para tomar el correlativo de
            // forma atómica frente a otras cajas.
            $cai = Cai::query()
                ->where('estado', 'activo')
                ->whereDate('fecha_limite_emision', '>=', now())
                ->lockForUpdate()
                ->first();

            if ($cai === null) {
                throw new SinCaiActivoException;
            }

            if ($cai->rangoAgotado()) {
                $cai->update(['estado' => 'agotado']);

                throw new RangoCaiAgotadoException($cai->id);
            }

            $correlativo = $cai->siguienteCorrelativo();
            $cai->update(['correlativo_actual' => $correlativo]);

            $numero = $cai->formatearNumero($correlativo);

            // Nombre siempre en mayúsculas. Si hay RTN, se guarda el cliente
            // frecuente para autocompletar en próximas ventas.
            $nombreMayus = mb_strtoupper(trim($nombreCliente));

            if ($rtn !== null) {
                Cliente::registrar((string) $rtn, $nombreMayus);
            }

            $factura = Factura::create([
                'venta_id'          => $venta->id,
                'cai_id'            => $cai->id,
                'correlativo'       => $correlativo,
                'numero'            => $numero,
                'detallada'         => $detallada,
                'hash_verificacion' => Factura::calcularHash($numero, $rtn !== null ? (string) $rtn : 'CF', $venta->total, $cai->id),
                'rtn_cliente'       => $rtn !== null ? (string) $rtn : null,
                'nombre_cliente'    => $nombreMayus,
                // Snapshot del pago AL EMITIR: la reimpresión siempre muestra
                // esto, aunque después se corrija el pago interno de la venta.
                'forma_pago'    => $venta->forma_pago,
                'pagos_detalle' => $venta->pagos()
                    ->get(['metodo', 'banco', 'monto'])
                    ->map(static fn ($p): array => ['metodo' => $p->metodo, 'banco' => $p->banco, 'monto' => (float) $p->monto])
                    ->all(),
                'gravado'        => $venta->gravado,
                'exento'         => $venta->exento,
                'subtotal_lista' => $venta->subtotal_lista,
                'descuento'      => $venta->descuento,
                'isv'            => $venta->isv,
                'total'          => $venta->total,
                'emitida_at'     => now(),
            ]);

            // Marca el CAI como agotado si este fue el último número.
            if ($cai->rangoAgotado()) {
                $cai->update(['estado' => 'agotado']);
            }

            event(new FacturaEmitida($factura));

            return $factura;
            // Falla cualquier paso → rollback: no queda correlativo
            // consumido ni factura a medias.
        });
    }

    /**
     * ¿Se puede anular esta factura todavía?
     *
     * Reglas (legales): no si ya está anulada; no si el período fiscal de
     * su emisión ya fue declarado al SAR; no si pasó el día límite de
     * anulación del mes siguiente (config empresa.dia_limite_anulacion).
     */
    public function puedeAnular(Factura $factura, ?Carbon $referencia = null): bool
    {
        return $this->motivoNoAnulable($factura, $referencia) === null;
    }

    /**
     * Anula la factura con motivo. NUNCA se borra ni se reutiliza el
     * correlativo (el número queda consumido ante el SAR).
     *
     * @throws FacturaNoAnulableException
     */
    public function anular(Factura $factura, string $motivo, ?int $usuarioId = null): Factura
    {
        return DB::transaction(function () use ($factura, $motivo, $usuarioId): Factura {
            $bloqueo = $this->motivoNoAnulable($factura);

            if ($bloqueo !== null) {
                throw new FacturaNoAnulableException($bloqueo);
            }

            $factura->update([
                'anulada'          => true,
                'motivo_anulacion' => $motivo,
                'anulada_at'       => now(),
            ]);

            // Si la venta salio de una cuenta prepago, la plata vuelve. Anular
            // sin devolver deja al cliente pagando algo que ya no existe.
            $this->devolverSaldo($factura, $usuarioId);

            activity()
                ->performedOn($factura)
                ->withProperties(['motivo' => $motivo, 'usuario_id' => $usuarioId])
                ->log('factura_anulada');

            return $factura;
        });
    }

    /**
     * Le devuelve a la cuenta prepago lo que esta venta le habia descontado.
     *
     * Se busca por el movimiento de consumo y NO por el RTN: la plata vuelve
     * exactamente a la cuenta de donde salio, aunque despues le hayan cambiado
     * el cliente. Anular esta bloqueado si la factura ya estaba anulada, asi
     * que no hay forma de devolver dos veces.
     */
    private function devolverSaldo(Factura $factura, ?int $usuarioId): void
    {
        $consumo = CuentaMovimiento::query()
            ->where('venta_id', $factura->venta_id)
            ->where('tipo', 'consumo')
            ->first();

        if ($consumo === null) {
            return;   // no se pago con saldo: no hay nada que devolver
        }

        $total = round((float) CuentaMovimiento::query()
            ->where('venta_id', $factura->venta_id)
            ->where('tipo', 'consumo')
            ->sum('monto'), 2);   // viene negativo

        if ($total >= 0) {
            return;
        }

        /** @var CuentaPrepago $cuenta */
        $cuenta = CuentaPrepago::query()->whereKey($consumo->cuenta_prepago_id)->firstOrFail();

        $this->cuentas->ajustar(
            $cuenta,
            abs($total),
            'Devolucion por anulacion de la factura '.$factura->numero,
            $usuarioId,
            $factura->venta,
        );
    }

    /** Devuelve el motivo por el que NO se puede anular, o null si sí se puede. */
    private function motivoNoAnulable(Factura $factura, ?Carbon $referencia = null): ?string
    {
        $hoy = $referencia ?? now();

        if ($factura->anulada) {
            return 'ya está anulada.';
        }

        $emitida = $factura->emitida_at;

        $periodoDeclarado = PeriodoFiscal::query()
            ->where('anio', $emitida->year)
            ->where('mes', $emitida->month)
            ->where('estado', 'declarado')
            ->exists();

        if ($periodoDeclarado) {
            return 'el período fiscal ya fue declarado al SAR.';
        }

        $diaLimite = EmpresaSetting::actual()->dia_limite_anulacion;
        $limite = $emitida->copy()->addMonthNoOverflow()->startOfMonth()->addDays($diaLimite - 1)->endOfDay();

        if ($hoy->greaterThan($limite)) {
            return "pasó el día límite de anulación ({$diaLimite} del mes siguiente).";
        }

        return null;
    }
}
