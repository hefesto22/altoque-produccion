<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contracts\CalculaImpuestos;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\CorteCaja;
use App\Models\Factura;
use App\Models\PeriodoFiscal;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Pos\VentaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Factura de una venta que se cobró en efectivo pero no se facturó en su
 * momento, con su fecha y hora reales. Se corre a mano, por SSH, y solo
 * como excepción (primer caso: L.285 de LDC, 09/11/2026 19:43).
 *
 * Sin --confirmar solo muestra lo que haría. Se niega a seguir si:
 *  - ya hay una factura emitida DESPUÉS de esa hora: la nueva toma el
 *    siguiente correlativo y quedaría fuera de orden cronológico;
 *  - el período fiscal de ese mes ya se declaró;
 *  - no hay CAI vigente para esa fecha;
 *  - ningún turno de caja cubre esa hora.
 *
 * La venta pasa por el mismo VentaService del POS, con el reloj puesto en
 * esa fecha: vendida_at, emitida_at y el número de orden salen de ese día.
 * Factura no detallada (una línea con el concepto de la empresa), y entra
 * al turno que estaba abierto a esa hora. Si ese turno ya cerró, se le
 * recalculan los totales: el dinero estuvo en esa gaveta.
 */
class RegistrarFacturaAtrasada extends Command
{
    protected $signature = 'facturas:registrar-atrasada
        {--fecha= : Fecha y hora de la venta, hora de Honduras (ej. "2026-11-09 19:43")}
        {--total= : Total cobrado, con ISV incluido (ej. 285)}
        {--rtn= : RTN del cliente, 14 dígitos}
        {--nombre= : Nombre del cliente (si el RTN ya está registrado se usa el nombre guardado)}
        {--cajero= : ID del usuario a quien se le registra (por defecto, el de la última venta de ese turno)}
        {--confirmar : Emite la factura. Sin esta opción solo muestra lo que haría}';

    protected $description = 'Factura en efectivo de una venta ya cobrada, con su fecha y hora reales (solo como excepción)';

    /** Línea interna de la venta. En el papel sale el concepto de la empresa ("Alimentación"). */
    public const PRODUCTO = 'ALIMENTACIÓN (FACTURA MANUAL)';

    public function handle(VentaService $ventas, CorteCajaService $caja, CalculaImpuestos $calculador): int
    {
        // ── Datos de entrada ────────────────────────────────────────────
        $fecha = $this->leerFecha();

        if ($fecha === null) {
            return $this->fallar('--fecha es obligatoria, con formato "AAAA-MM-DD HH:MM" (ej. "2026-11-09 19:43").');
        }

        if ($fecha->greaterThanOrEqualTo(now())) {
            return $this->fallar('La fecha tiene que ser anterior a este momento.');
        }

        $totalTexto = $this->opcion('total');

        if ($totalTexto === null || ! is_numeric($totalTexto) || (float) $totalTexto <= 0) {
            return $this->fallar('--total es obligatorio y tiene que ser mayor que cero (ej. 285).');
        }

        $total = round((float) $totalTexto, 2);

        try {
            $rtn = new RTN((string) $this->opcion('rtn'));
        } catch (Throwable) {
            return $this->fallar('--rtn es obligatorio: 14 dígitos, sin guiones ni espacios.');
        }

        // Si el cliente ya está registrado se respeta su nombre guardado:
        // emitir con otro nombre lo sobrescribiría en el catálogo.
        $guardado = Cliente::query()->where('rtn', (string) $rtn)->value('nombre');
        $pedido = $this->opcion('nombre');
        $nombre = is_string($guardado) && $guardado !== '' ? $guardado : $pedido;

        if ($nombre === null) {
            return $this->fallar('Ese RTN no está registrado como cliente: pasa el nombre con --nombre.');
        }

        if ($pedido !== null && is_string($guardado) && mb_strtoupper($pedido) !== mb_strtoupper($guardado)) {
            $this->warn("El RTN ya está registrado como \"{$guardado}\": se usa ese nombre, no \"{$pedido}\".");
        }

        // ── Reglas fiscales ─────────────────────────────────────────────
        $periodo = PeriodoFiscal::query()->where('anio', $fecha->year)->where('mes', $fecha->month)->first();

        if ($periodo !== null && $periodo->estaDeclarado()) {
            return $this->fallar("El período {$periodo->etiqueta()} ya se declaró al SAR: no se le pueden agregar facturas.");
        }

        // Anuladas incluidas: también gastaron su correlativo en su fecha.
        $posterior = Factura::query()->where('emitida_at', '>', $fecha)->orderBy('emitida_at')->first(['numero', 'emitida_at']);

        if ($posterior !== null) {
            return $this->fallar(
                "Ya hay facturas emitidas después de esa hora (la primera: {$posterior->numero} del "
                .$posterior->emitida_at->format('d/m/Y H:i').'). La nueva quedaría con un correlativo fuera de orden.'
            );
        }

        $anterior = Factura::query()->where('emitida_at', '<=', $fecha)->orderByDesc('emitida_at')->first(['numero', 'emitida_at']);

        $cai = Cai::query()
            ->where('estado', 'activo')
            ->whereDate('fecha_autorizacion', '<=', $fecha)
            ->whereDate('fecha_limite_emision', '>=', $fecha)
            ->first();

        if ($cai === null) {
            return $this->fallar('No hay un CAI activo y vigente para esa fecha.');
        }

        // ── Turno y cajero ──────────────────────────────────────────────
        $corte = CorteCaja::query()
            ->where('abierto_at', '<=', $fecha)
            ->where(static fn ($q) => $q->where('estado', 'abierto')->orWhere('cerrado_at', '>=', $fecha))
            ->orderByDesc('abierto_at')
            ->first();

        if ($corte === null) {
            return $this->fallar('Ningún turno de caja estaba abierto a esa hora.');
        }

        $cajeroTexto = $this->opcion('cajero');
        $ultimoCajero = Venta::query()
            ->where('corte_caja_id', $corte->id)
            ->where('vendida_at', '<=', $fecha)
            ->orderByDesc('vendida_at')
            ->value('cajero_id');
        $cajeroId = $cajeroTexto !== null
            ? (int) $cajeroTexto
            : (is_numeric($ultimoCajero) ? (int) $ultimoCajero : (int) $corte->cajero_id);
        $cajero = User::query()->find($cajeroId);

        if ($cajero === null) {
            return $this->fallar("No existe el usuario #{$cajeroId}.");
        }

        // ── Vista previa (no guarda nada) ───────────────────────────────
        $resumen = $calculador->calcular([new LineaVenta(0, self::PRODUCTO, $total, 1, gravaIsv: true)]);

        $this->table(['', 'Se va a registrar'], [
            ['Fecha y hora', $fecha->format('d/m/Y H:i')],
            ['Cliente', mb_strtoupper($nombre).' · RTN '.$rtn],
            ['Factura', $cai->formatearNumero($cai->siguienteCorrelativo()).' · no detallada'],
            ['Factura anterior', $anterior !== null ? $anterior->numero.' · '.$anterior->emitida_at->format('d/m/Y H:i') : '—'],
            ['Gravado', number_format($resumen->gravado, 2)],
            ['ISV', number_format($resumen->isv, 2)],
            ['Total', number_format($resumen->total, 2)],
            ['Pago', 'Efectivo'],
            ['Turno', "#{$corte->id} · abrió ".$corte->abierto_at->format('d/m H:i')
                .($corte->estado === 'cerrado' && $corte->cerrado_at !== null ? ' · cerró '.$corte->cerrado_at->format('d/m H:i').' (se recalcula)' : ' · abierto')],
            ['Cajero', $cajero->name],
        ]);

        if (! $this->option('confirmar')) {
            $this->info('No se guardó nada. Para emitirla, corre el mismo comando con --confirmar.');

            return self::SUCCESS;
        }

        // ── Emisión, con el reloj en la fecha de la venta ──────────────
        $relojAntes = Carbon::getTestNow();
        Carbon::setTestNow($fecha);

        try {
            $factura = DB::transaction(function () use ($ventas, $caja, $corte, $cajero, $rtn, $nombre, $total, $fecha, $relojAntes): Factura {
                $producto = Producto::query()->firstOrCreate(
                    ['nombre' => self::PRODUCTO, 'es_sistema' => false],
                    ['categoria' => 'extra', 'precio' => 0, 'grava_isv' => true, 'activo' => false],
                );

                $factura = $ventas->registrarFactura(
                    [new LineaVenta($producto->id, self::PRODUCTO, $total, 1, gravaIsv: true)],
                    $cajero->id,
                    $rtn,
                    $nombre,
                    'efectivo',
                    detallada: false,
                );

                // El VentaService la mete al turno abierto AHORA (si hay uno);
                // va al turno que estaba abierto a la hora de la venta.
                Venta::query()->findOrFail($factura->venta_id)->update(['corte_caja_id' => $corte->id]);

                // Solo la venta y la factura son "de ese día". El recálculo del
                // turno y la bitácora llevan la hora real en que se hizo.
                Carbon::setTestNow($relojAntes);
                $caja->recalcularCerrado($corte);

                activity()
                    ->performedOn($factura)
                    ->withProperties(['fecha_venta' => $fecha->toDateTimeString(), 'corte_caja_id' => $corte->id])
                    ->log('factura_atrasada_registrada');

                return $factura;
            });
        } catch (Throwable $e) {
            return $this->fallar('No se emitió nada: '.$e->getMessage());
        } finally {
            Carbon::setTestNow($relojAntes);
        }

        $corte->refresh();

        $this->info("Factura {$factura->numero} emitida con fecha {$fecha->format('d/m/Y H:i')}.");
        $this->line("Turno #{$corte->id}: {$corte->cantidad_ventas} ventas · total L.".number_format((float) $corte->total_ventas, 2)
            .' · efectivo L.'.number_format((float) $corte->total_efectivo, 2)
            .' · diferencia '.($corte->diferencia !== null ? 'L.'.number_format((float) $corte->diferencia, 2) : 'sin conteo'));

        return self::SUCCESS;
    }

    private function leerFecha(): ?Carbon
    {
        $texto = $this->opcion('fecha');

        if ($texto === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i', $texto, (string) config('app.timezone'))?->startOfMinute();
        } catch (Throwable) {
            return null;
        }
    }

    private function opcion(string $nombre): ?string
    {
        $valor = $this->option($nombre);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    private function fallar(string $mensaje): int
    {
        $this->error($mensaje);

        return self::FAILURE;
    }
}
