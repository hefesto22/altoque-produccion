<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Cuenta prepago de una empresa o persona: deja dinero por adelantado y
 * consume contra ese saldo.
 *
 * El depósito NO es venta (no consume CAI ni lleva ISV): es plata del cliente
 * que el negocio todavía debe. La factura sale en cada consumo. Ver la
 * migración `create_cuentas_prepago` para el porqué fiscal completo.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string $token
 * @property string $saldo
 * @property bool $permite_credito
 * @property string $limite_credito
 * @property bool $activa
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CuentaPrepago extends Model
{
    protected $table = 'cuentas_prepago';

    /** @var array<int, string> */
    protected $fillable = [
        'nombre',
        'rtn',
        'telefono',
        'token',
        'saldo',
        'permite_credito',
        'limite_credito',
        'activa',
        'notas',
    ];

    /**
     * Defaults que espejan los de la base.
     *
     * Sin esto, una cuenta recién creada devuelve `null` en saldo, crédito y
     * activa hasta que alguien la relea: el modelo mentiría sobre su propio
     * estado y cualquier `if ($cuenta->permite_credito)` decidiría con basura.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'saldo'           => 0,
        'permite_credito' => false,
        'limite_credito'  => 0,
        'activa'          => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'saldo'           => 'decimal:2',
            'limite_credito'  => 'decimal:2',
            'permite_credito' => 'boolean',
            'activa'          => 'boolean',
        ];
    }

    /**
     * El token del link público se genera solo y NUNCA se regenera al editar:
     * el cliente guarda ese link en su WhatsApp y tiene que seguir sirviendo.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $cuenta): void {
            if (($cuenta->token ?? '') === '') {
                $cuenta->token = Str::random(40);
            }

            $cuenta->nombre = mb_strtoupper(trim($cuenta->nombre));
        });
    }

    /** @return HasMany<CuentaMovimiento, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(CuentaMovimiento::class)->latest('id');
    }

    /**
     * Cuánto puede gastar HOY: el saldo, más el tope de crédito si la cuenta
     * lo tiene habilitado. Con crédito el saldo puede quedar negativo.
     */
    public function disponible(): float
    {
        $saldo = (float) $this->saldo;

        return $this->permite_credito
            ? round($saldo + (float) $this->limite_credito, 2)
            : $saldo;
    }

    /** ¿Alcanza para este consumo? */
    public function alcanzaPara(float $monto): bool
    {
        return round($monto, 2) <= $this->disponible() + 0.009;
    }

    /** Total depositado histórico (para el estado de cuenta). */
    public function totalDepositado(): float
    {
        return round((float) $this->movimientos()->where('tipo', 'deposito')->sum('monto'), 2);
    }

    /** Total consumido histórico, en positivo (los consumos se guardan en negativo). */
    public function totalConsumido(): float
    {
        return round(abs((float) $this->movimientos()->where('tipo', 'consumo')->sum('monto')), 2);
    }

    /** URL firmada del estado de cuenta que ve el cliente. */
    public function urlEstado(): string
    {
        return URL::signedRoute('cuentas.estado', ['token' => $this->token]);
    }

    /** Link de WhatsApp con el estado de cuenta para el cliente. */
    public function urlWhatsApp(): string
    {
        $mensaje = "Estado de su cuenta en {$this->nombre}: saldo L. "
            .number_format((float) $this->saldo, 2)
            .'. Consultelo aquí: '.$this->urlEstado();

        return 'https://wa.me/'.($this->telefono !== null ? preg_replace('/\D/', '', $this->telefono) : '')
            .'?text='.rawurlencode($mensaje);
    }

    /** @param  Builder<CuentaPrepago>  $query */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
