<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un movimiento del libro mayor de una cuenta prepago. Se agrega, nunca se
 * borra ni se edita: es el respaldo de por qué el saldo es el que es.
 *
 * `monto` va CON SIGNO — depósito +, consumo −, ajuste cualquiera — así el
 * saldo es la suma de los movimientos y se puede auditar sumando.
 *
 * @property int $id
 * @property int $cuenta_prepago_id
 * @property string $tipo
 * @property string $monto
 * @property string $saldo_despues
 * @property int|null $venta_id
 * @property string|null $forma_pago
 * @property string|null $banco
 * @property string|null $referencia
 * @property int|null $corte_caja_id
 * @property string|null $notas
 * @property int|null $registrado_por
 * @property Carbon $created_at
 */
class CuentaMovimiento extends Model
{
    protected $table = 'cuenta_movimientos';

    /** @var array<int, string> */
    protected $fillable = [
        'cuenta_prepago_id',
        'tipo',
        'monto',
        'saldo_despues',
        'venta_id',
        'forma_pago',
        'banco',
        'referencia',
        'corte_caja_id',
        'notas',
        'registrado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'         => 'decimal:2',
            'saldo_despues' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CuentaPrepago, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPrepago::class, 'cuenta_prepago_id');
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function esDeposito(): bool
    {
        return $this->tipo === 'deposito';
    }

    public function esConsumo(): bool
    {
        return $this->tipo === 'consumo';
    }

    /** Etiqueta legible para el estado de cuenta del cliente. */
    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'deposito' => 'Depósito',
            'consumo'  => 'Consumo',
            default    => 'Ajuste',
        };
    }

    /** Cómo entró la plata, escrito para el cliente (solo depósitos). */
    public function formaLabel(): ?string
    {
        if ($this->forma_pago === null) {
            return null;
        }

        $texto = match ($this->forma_pago) {
            'efectivo'      => 'Efectivo',
            'transferencia' => 'Transferencia',
            'cheque'        => 'Cheque',
            default         => ucfirst($this->forma_pago),
        };

        foreach ([$this->banco, $this->referencia] as $extra) {
            if ($extra !== null && trim($extra) !== '') {
                $texto .= ' · '.$extra;
            }
        }

        return $texto;
    }
}
