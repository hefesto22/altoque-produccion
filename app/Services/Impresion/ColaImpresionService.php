<?php

declare(strict_types=1);

namespace App\Services\Impresion;

use App\Models\Impresion;
use App\Support\Acceso;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Cola de impresión: decide DÓNDE sale el papel y lo saca en tandas.
 *
 * Hay una sola térmica y está en la computadora de la caja. Quien tiene el
 * permiso `ImprimirDirecto` (caja) imprime en el acto, igual que siempre; el
 * resto — las tablets del salón — deja el trabajo pendiente y la caja lo saca
 * desde el panel del POS.
 *
 * En hora pico se juntan decenas de pendientes, así que la caja no imprime de
 * a uno: reclama toda la tanda y la manda como UN documento con salto de
 * página entre tickets. Un diálogo, un envío, y la térmica corta entre uno y
 * otro. Las comandas van por su lado y las facturas por el suyo, porque el
 * papel de cada una termina en un lugar distinto: cocina y cliente.
 *
 * REGLA DURA: enviar() NUNCA lanza y NUNCA se llama dentro de la transacción
 * de la venta. Los correlativos SAR salen de secuencias de Postgres, que no
 * son transaccionales: un rollback quema el número y abre un hueco en la
 * serie. La venta queda registrada aunque falle la impresión — ese contrato
 * no se rompe jamás.
 */
final class ColaImpresionService
{
    /** Tope de trabajos por tanda: un rollo interminable no le sirve a nadie. */
    public const MAX_TANDA = 60;

    /**
     * Qué tipos entran en cada botón. El corte de caja no está en ninguno: sale
     * una vez al día y arma sus datos con consultas propias, así que se imprime
     * solo con su botón individual.
     *
     * @var array<string, array<int, string>>
     */
    private const FILTROS = [
        'comanda' => ['comanda'],
        'factura' => ['factura', 'documentos'],
        'todo'    => ['comanda', 'factura', 'documentos'],
    ];

    /**
     * Encola el documento y devuelve la URL si a este usuario le toca sacarlo
     * acá mismo; null si queda esperando a la caja.
     *
     * `etiqueta` y `detalle` son un snapshot: se congelan al encolar, así la
     * pantalla de la caja se lee igual aunque después cambie la venta.
     */
    public function enviar(string $tipo, int $referenciaId, string $etiqueta, ?string $detalle = null): ?string
    {
        $url = (new Impresion(['tipo' => $tipo, 'referencia_id' => $referenciaId]))->url();

        // El documento no existe (p. ej. una venta sin comanda): no se encola
        // basura ni se manda a imprimir un 404.
        if ($url === null) {
            return null;
        }

        $imprimeAca = Acceso::puede('ImprimirDirecto');
        $usuarioId = $this->usuarioId();

        try {
            Impresion::create([
                'tipo'           => $tipo,
                'referencia_id'  => $referenciaId,
                'etiqueta'       => mb_strtoupper(trim($etiqueta)),
                'detalle'        => trim((string) $detalle) !== '' ? trim((string) $detalle) : null,
                'estado'         => $imprimeAca ? 'impreso' : 'pendiente',
                'solicitado_por' => $usuarioId,
                'impreso_por'    => $imprimeAca ? $usuarioId : null,
                'impreso_at'     => $imprimeAca ? now() : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            // Fail-open a propósito: si la cola se cae, el papel sale donde
            // esté el usuario. Un ticket de más se tira; uno perdido, no.
            return $url;
        }

        return $imprimeAca ? $url : null;
    }

    /**
     * Reclama un pendiente para imprimirlo. El UPDATE condicionado al estado
     * es el candado: si dos pestañas le dan al mismo botón, solo una gana y el
     * ticket sale una sola vez.
     */
    public function reclamar(int $id): ?Impresion
    {
        $gano = Impresion::query()
            ->whereKey($id)
            ->where('estado', 'pendiente')
            ->update([
                'estado'      => 'impreso',
                'impreso_por' => $this->usuarioId(),
                'impreso_at'  => now(),
                'updated_at'  => now(),
            ]);

        return $gano === 1 ? Impresion::find($id) : null;
    }

    /**
     * Reclama de un saque todos los pendientes del filtro y devuelve los ids
     * que ESTE usuario ganó (los que se llevó otra caja quedan afuera).
     *
     * @return array<int, int>
     */
    public function reclamarTanda(string $filtro): array
    {
        $ganados = [];

        foreach ($this->pendientesDe($filtro)->take(self::MAX_TANDA) as $pendiente) {
            if ($this->reclamar((int) $pendiente->id) !== null) {
                $ganados[] = (int) $pendiente->id;
            }
        }

        return $ganados;
    }

    /**
     * URL firmada del documento único que junta todos esos trabajos.
     *
     * @param array<int, int> $ids
     */
    public function urlTanda(array $ids): string
    {
        return URL::signedRoute('impresiones.tanda', ['ids' => implode(',', $ids)]);
    }

    /** Descarta un pendiente sin imprimirlo (pedido anulado, prueba, duplicado). */
    public function cancelar(int $id): bool
    {
        return Impresion::query()
            ->whereKey($id)
            ->where('estado', 'pendiente')
            ->update(['estado' => 'cancelado', 'updated_at' => now()]) === 1;
    }

    /** @return Collection<int, Impresion> */
    public function pendientes(): Collection
    {
        return Impresion::query()->pendientes()->get();
    }

    /**
     * Pendientes de un botón concreto: 'comanda', 'factura' (incluye el
     * documento factura+comanda) o 'todo'.
     *
     * @return Collection<int, Impresion>
     */
    public function pendientesDe(string $filtro): Collection
    {
        return Impresion::query()
            ->pendientes()
            ->whereIn('tipo', self::FILTROS[$filtro] ?? self::FILTROS['todo'])
            ->get();
    }

    /**
     * Cuántos pendientes hay por botón, para los contadores del panel.
     *
     * @return array{comanda: int, factura: int, todo: int, total: int}
     */
    public function conteoPendientes(): array
    {
        $porTipo = Impresion::query()
            ->where('estado', 'pendiente')
            ->selectRaw('tipo, count(*) as n')
            ->groupBy('tipo')
            ->pluck('n', 'tipo');

        $de = static fn (string $t): int => (int) ($porTipo[$t] ?? 0);

        $comanda = $de('comanda');
        $factura = $de('factura') + $de('documentos');

        return [
            'comanda' => $comanda,
            'factura' => $factura,
            'todo'    => $comanda + $factura,
            // El corte entra en el total (se ve en la lista) pero no en las tandas.
            'total' => (int) $porTipo->sum(),
        ];
    }

    public function contarPendientes(): int
    {
        return Impresion::query()->where('estado', 'pendiente')->count();
    }

    /**
     * Impresos recientes, para reimprimir. No es un lujo: es la única vía de
     * recuperación cuando el papel se atasca, cuando alguien cancela el
     * diálogo a mitad de una tanda, o cuando alguien de caja entra desde una
     * tablet y el trabajo se marca impreso sin que salga nada.
     *
     * @return Collection<int, Impresion>
     */
    public function recientes(int $minutos = 120, int $limite = 20): Collection
    {
        return Impresion::query()
            ->where('estado', 'impreso')
            ->where('impreso_at', '>=', now()->subMinutes($minutos))
            ->orderByDesc('impreso_at')
            ->limit($limite)
            ->get();
    }

    private function usuarioId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }
}
