<?php

declare(strict_types=1);

namespace App\Services\Cuentas;

use App\Domain\Exceptions\SaldoInsuficienteException;
use App\Models\CuentaMovimiento;
use App\Models\CuentaPrepago;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de una cuenta prepago. TODO pasa por acá: nadie toca la columna
 * `saldo` a mano.
 *
 * La regla que gobierna el servicio: cada movimiento se escribe con la fila de
 * la cuenta BLOQUEADA (`lockForUpdate`). Sin eso, dos cajas cobrando a la misma
 * empresa en el mismo segundo leerían el mismo saldo y una de las dos ventas se
 * comería el dinero de la otra — y el error solo se vería al cuadrar a fin de
 * mes, cuando ya nadie se acuerda.
 *
 * El saldo se guarda en columna Y se puede reconstruir sumando los movimientos
 * (van con signo). Esa redundancia es a propósito: la columna hace rápido el
 * cobro y la suma permite auditar que nada se perdió.
 */
final class CuentaPrepagoService
{
    /**
     * Entra plata a la cuenta.
     *
     * ⚠️ Desde 2026-08-17 el depósito SÍ es una venta: se factura al momento
     * de recibirlo (ver VentaService::registrarDeposito, que es quien debe
     * llamar a este método). Acá solo se acredita el saldo y se deja el
     * rastro; la factura y el ISV los maneja la venta.
     *
     * @param string $formaPago efectivo | transferencia | cheque
     * @param int|null $corteCajaId turno al que entra (hoy lo maneja la venta)
     * @param Venta|null $venta la venta con la que se facturó este abono
     */
    public function depositar(
        CuentaPrepago $cuenta,
        float $monto,
        string $formaPago,
        ?string $banco = null,
        ?string $referencia = null,
        ?string $notas = null,
        ?int $usuarioId = null,
        ?int $corteCajaId = null,
        ?Venta $venta = null,
    ): CuentaMovimiento {
        return $this->registrar($cuenta, 'deposito', abs(round($monto, 2)), [
            'forma_pago'    => $formaPago,
            'banco'         => $banco,
            'referencia'    => $referencia,
            'notas'         => $notas,
            'corte_caja_id' => $formaPago === 'efectivo' ? $corteCajaId : null,
            // La venta con la que se facturó el abono: sin factura detrás,
            // un saldo acreditado es plata que entró sin documento.
            'venta_id' => $venta?->id,
        ], $usuarioId);
    }

    /**
     * Descuenta un consumo. Va con la venta que lo respalda: el descuento sin
     * factura detrás es un agujero.
     *
     * @throws SaldoInsuficienteException
     */
    public function consumir(
        CuentaPrepago $cuenta,
        float $monto,
        ?Venta $venta = null,
        ?int $usuarioId = null,
        ?string $notas = null,
    ): CuentaMovimiento {
        return $this->registrar($cuenta, 'consumo', -abs(round($monto, 2)), [
            'venta_id' => $venta?->id,
            'notas'    => $notas,
        ], $usuarioId);
    }

    /**
     * Corrección manual (se depositó de más, se cobró mal, cortesía). Lleva
     * motivo obligatorio: un ajuste sin explicación es plata que se movió sin
     * que nadie sepa por qué.
     *
     * @param float $monto positivo suma, negativo resta
     *
     * @throws SaldoInsuficienteException
     */
    public function ajustar(CuentaPrepago $cuenta, float $monto, string $motivo, ?int $usuarioId = null, ?Venta $venta = null, bool $forzar = false): CuentaMovimiento
    {
        return $this->registrar($cuenta, 'ajuste', round($monto, 2), [
            'notas'    => $motivo,
            'venta_id' => $venta?->id,
        ], $usuarioId, $forzar);
    }

    /**
     * Núcleo: bloquea la cuenta, valida, escribe el movimiento y deja el saldo
     * nuevo en la cuenta. Todo dentro de una transacción.
     *
     * @param array<string, mixed> $extra
     *
     * @throws SaldoInsuficienteException
     */
    private function registrar(CuentaPrepago $cuenta, string $tipo, float $monto, array $extra, ?int $usuarioId, bool $forzar = false): CuentaMovimiento
    {
        return DB::transaction(function () use ($cuenta, $tipo, $monto, $extra, $usuarioId, $forzar): CuentaMovimiento {
            /** @var CuentaPrepago $fresca */
            $fresca = CuentaPrepago::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            // Lo que resta (consumo o ajuste negativo) tiene que caber en lo
            // disponible: saldo, más el crédito si la cuenta lo tiene.
            //
            // `forzar` existe para UN caso: revertir un depósito cuya factura
            // se anuló. Ahí el saldo tiene que irse sí o sí — la plata dejó de
            // estar documentada — aunque la cuenta quede debajo de su tope.
            if (! $forzar && $monto < 0 && ! $fresca->alcanzaPara(abs($monto))) {
                throw new SaldoInsuficienteException($fresca->disponible(), abs($monto));
            }

            $saldoDespues = round((float) $fresca->saldo + $monto, 2);

            $movimiento = $fresca->movimientos()->create([
                'tipo'           => $tipo,
                'monto'          => $monto,
                'saldo_despues'  => $saldoDespues,
                'registrado_por' => $usuarioId,
                ...$extra,
            ]);

            $fresca->update(['saldo' => $saldoDespues]);

            // El objeto que trajo quien llama también queda al día: si no, la
            // pantalla que lo mostró seguiría enseñando el saldo viejo.
            $cuenta->setAttribute('saldo', $saldoDespues);

            return $movimiento;
        });
    }
}
