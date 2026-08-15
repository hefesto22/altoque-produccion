<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un documento esperando salir por la única impresora térmica del local.
 *
 * Existe porque los pedidos entran desde tablets pero el papel sale en la
 * computadora de la caja — y sin pantalla en cocina, un ticket que no se
 * imprime es un pedido perdido.
 *
 * Qué apunta cada tipo:
 *   comanda    → Comanda    (ticket de cocina)
 *   factura    → Factura    (ticket fiscal)
 *   documentos → Factura    ¡ojo! no es un documento propio: es factura +
 *                           comanda en un solo HTML, y referencia_id es el
 *                           id de la FACTURA
 *   corte      → CorteCaja  (ticket del cierre de turno)
 *
 * @property int $id
 * @property string $tipo
 * @property int $referencia_id
 * @property string $etiqueta
 * @property string|null $detalle
 * @property string $estado
 * @property int|null $solicitado_por
 * @property int|null $impreso_por
 * @property Carbon|null $impreso_at
 * @property Carbon $created_at
 */
class Impresion extends Model
{
    use MassPrunable;

    protected $table = 'impresiones';

    /** @var array<int, string> */
    protected $fillable = [
        'tipo',
        'referencia_id',
        'etiqueta',
        'detalle',
        'estado',
        'solicitado_por',
        'impreso_por',
        'impreso_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'referencia_id' => 'integer',
            'impreso_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function impresoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impreso_por');
    }

    /**
     * URL firmada del documento, RECONSTRUIDA en cada lectura.
     *
     * No se guarda la URL a propósito: URL::signedRoute() firma con APP_KEY, y
     * guardarla dejaría toda la cola inservible el día que se rote la key.
     *
     * Devuelve null si el documento ya no existe — sin esto el iframe cargaría
     * un 404 y lo mandaría igual a la térmica.
     */
    public function url(): ?string
    {
        return match ($this->tipo) {
            'comanda'    => Comanda::find($this->referencia_id)?->urlTicket(),
            'factura'    => Factura::find($this->referencia_id)?->urlTicket(),
            'documentos' => Factura::find($this->referencia_id)?->urlDocumentos(),
            'corte'      => CorteCaja::find($this->referencia_id)?->urlTicket(),
            default      => null,
        };
    }

    /**
     * URL de UNA parte del documento combinado (factura + comanda).
     *
     * El cliente pidió poder sacar solo el papel que hace falta: si la
     * cocina perdió su comanda no hay por qué volver a imprimir la factura
     * fiscal, y al revés. En cualquier otro tipo la parte se ignora — un
     * ticket suelto no tiene mitades.
     *
     * La comanda sale de la misma relación que usa el documento combinado
     * (`venta.comanda`), así que reimprimir "solo la comanda" da exactamente
     * el papel que salió pegado a la factura.
     *
     * @param string $parte 'factura' | 'comanda' | cualquier otra cosa = ambas
     */
    public function urlParte(string $parte): ?string
    {
        if (! $this->esCombinada()) {
            return $this->url();
        }

        $factura = Factura::find($this->referencia_id);

        return match ($parte) {
            'factura' => $factura?->urlTicket(),
            'comanda' => $factura?->venta?->comanda?->urlTicket(),
            default   => $factura?->urlDocumentos(),
        };
    }

    /** ¿Es el documento de factura + comanda, el único que se puede partir? */
    public function esCombinada(): bool
    {
        return $this->tipo === 'documentos';
    }

    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'comanda'    => 'Comanda',
            'factura'    => 'Factura',
            'documentos' => 'Factura + comanda',
            'corte'      => 'Corte de caja',
            default      => 'Documento',
        };
    }

    /** Fiscal = el cliente está parado en la caja esperando. Se imprime primero. */
    public function esFiscal(): bool
    {
        return $this->tipo === 'factura' || $this->tipo === 'documentos';
    }

    /** @param  Builder<Impresion>  $query */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', 'pendiente')->orderBy('created_at');
    }

    /**
     * Poda diaria (model:prune, 05:00 — ya agendado en routes/console.php).
     * NUNCA borra pendientes: un ticket que la cocina no recibió no se
     * desvanece solo, se queda ahí hasta que alguien lo imprima o lo cancele.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('estado', ['impreso', 'cancelado'])
            ->where('created_at', '<', now()->subDays(60));
    }
}
