<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Exceptions\RestauranteException;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cliente;
use App\Models\CorteCaja;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Cocina\ReposicionService;
use App\Services\Pos\CotizadorVenta;
use App\Services\Pos\MenuDiaService;
use App\Services\Pos\VentaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Pantalla de cobro del POS. Velocidad ante todo: el cajero arma
 * proteína + complementos (precio de combo automático), agrega
 * bebidas/extras y cierra en un toque como Recibo o Factura (con RTN).
 *
 * Toda la lógica de precios vive en CotizadorVenta y el registro en
 * VentaService; esta página solo orquesta el estado de la UI.
 *
 * Tolerante a fallos: si algo falla al cobrar, se notifica y el carrito
 * no se pierde; la venta solo se limpia cuando se registró con éxito.
 */
class PuntoDeVenta extends Page
{
    protected string $view = 'filament.pages.punto-de-venta';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Punto de Venta';
    }

    public static function getNavigationLabel(): string
    {
        return 'Punto de Venta';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Caja';
    }

    public static function canAccess(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'cajero']);
    }

    /** Proteína seleccionada para el plato en construcción. */
    public ?int $proteinaId = null;

    /** @var array<int, int> ids de complementos del plato en construcción (puede repetir) */
    public array $complementoSel = [];

    /** Cuántos platos idénticos agregar de una (proteína + complementos). */
    public int $cantidadPlato = 1;

    /** @var array<int, array<string, mixed>> líneas ya agregadas a la venta */
    public array $carrito = [];

    // ── Modal de factura ────────────────────────────────────────────────
    public bool $mostrarFactura = false;

    public string $rtnInput = '';

    public string $nombreInput = '';

    /** En la factura con RTN: detallar todo o facturar como "Alimentación". */
    public bool $facturaDetallada = false;

    /** @var array<int, array{rtn: string, nombre: string}> sugerencias de clientes */
    public array $sugerencias = [];

    // ── Menú cargado una vez (cambia poco) ──────────────────────────────
    /** @var array<int, Producto> */
    public array $proteinas = [];

    /** @var array<int, Producto> */
    public array $complementos = [];

    /** @var array<int, Producto> */
    public array $bebidas = [];

    /** @var array<int, Producto> */
    public array $extras = [];

    /** @var array<int, Producto> combos promocionales con nombre */
    public array $combos = [];

    /** Servicio activo (desayuno/almuerzo/cena) que filtra el menú. */
    public ?int $servicioId = null;

    /** @var array<int, Servicio> */
    public array $servicios = [];

    /**
     * Tipo de orden: 'local' (se consume en el local; cada línea define
     * Aquí/Llevar) o 'domicilio' (toda la orden va a domicilio).
     */
    public string $tipoServicio = 'local';

    // Datos del cliente para domicilio.
    public string $domNombre = '';

    public string $domTelefono = '';

    public string $domIdentidad = '';

    public string $domDireccion = '';

    /** @var array<int, int> ids de productos con alerta de reposición activa */
    public array $productosBajos = [];

    // ── Turno de caja ───────────────────────────────────────────────────
    public bool $turnoAbierto = false;

    public ?int $corteId = null;

    public ?string $turnoDesde = null;

    public string $fondoInicial = '';

    public bool $mostrarCierre = false;

    public string $efectivoContado = '';

    public string $notasCierre = '';

    /** Forma de pago de la venta en curso. */
    public string $formaPago = 'efectivo';

    /** Banco de la transferencia (solo si formaPago = transferencia). */
    public string $banco = '';

    public function mount(): void
    {
        $this->servicios = Servicio::query()->activos()->get()->all();
        $this->servicioId = Servicio::activoAhora()?->id ?? ($this->servicios[0]['id'] ?? null);
        $this->productosBajos = app(ReposicionService::class)->productosConAlerta();

        $this->cargarTurno();
        $this->cargarMenu();

        // Si se viene de "Anular y corregir", precarga ese pedido al carrito.
        $rehacer = request()->integer('rehacer');

        if ($rehacer > 0) {
            $this->precargarVenta($rehacer);
        }
    }

    /** Precarga los items de una venta anulada para corregirla y re-facturar. */
    private function precargarVenta(int $ventaId): void
    {
        $venta = Venta::with(['items', 'factura'])->find($ventaId);

        if ($venta === null) {
            return;
        }

        foreach ($venta->items as $item) {
            $this->carrito[] = [
                'key'         => uniqid('l', true),
                'tipo'        => ! empty($item->detalle) ? 'plato' : 'producto',
                'destino'     => 'aqui',
                'producto_id' => $item->producto_id,
                'nombre'      => $item->nombre,
                'precio'      => (float) $item->precio_unitario,
                'cantidad'    => (int) $item->cantidad,
                'grava_isv'   => (bool) $item->grava_isv,
                'detalle'     => $item->detalle ?? [],
            ];
        }

        // Precarga forma de pago, banco y (si tenía) los datos de RTN.
        $this->formaPago = $venta->forma_pago ?? 'efectivo';
        $this->banco = $venta->banco ?? '';

        if ($venta->rtn_cliente !== null && $venta->rtn_cliente !== '') {
            $this->rtnInput = $venta->rtn_cliente;
            $this->nombreInput = (string) $venta->nombre_cliente;
            $this->facturaDetallada = (bool) ($venta->factura?->detallada ?? false);
        }

        $mensaje = ($venta->rtn_cliente !== null && $venta->rtn_cliente !== '')
            ? 'Ajustá lo que falte y tocá “Factura con RTN” (los datos ya están cargados).'
            : 'Ajustá lo que haga falta y volvé a facturar.';

        Notification::make()
            ->title('Pedido cargado para corregir')
            ->body($mensaje)
            ->warning()
            ->send();
    }

    private function cargarTurno(): void
    {
        $corte = app(CorteCajaService::class)->abierto((int) Auth::id());
        $this->turnoAbierto = $corte !== null;
        $this->corteId = $corte?->id;
        $this->turnoDesde = $corte?->abierto_at?->format('d/m/Y h:i A');
    }

    public function abrirTurno(): void
    {
        $fondo = is_numeric($this->fondoInicial) ? (float) $this->fondoInicial : 0.0;

        app(CorteCajaService::class)->abrir((int) Auth::id(), $fondo);
        $this->fondoInicial = '';
        $this->cargarTurno();

        Notification::make()->title('Turno abierto')->body('Ya podés cobrar.')->success()->send();
    }

    /** @return array{ventas: int, total: float, efectivo: float, tarjeta: float, transferencia: float, esperado: float, fondo: float} */
    public function getResumenTurnoProperty(): array
    {
        $corte = $this->corteId !== null ? CorteCaja::find($this->corteId) : null;

        if ($corte === null) {
            return ['ventas' => 0, 'total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'esperado' => 0, 'fondo' => 0];
        }

        $fila = Venta::query()
            ->where('corte_caja_id', $corte->id)
            ->selectRaw("count(*) c, coalesce(sum(total),0) t,
                coalesce(sum(total) filter (where forma_pago='efectivo'),0) ef,
                coalesce(sum(total) filter (where forma_pago='tarjeta'),0) ta,
                coalesce(sum(total) filter (where forma_pago='transferencia'),0) tr")
            ->first();

        $efectivo = (float) $fila->ef;

        return [
            'ventas'        => (int) $fila->c,
            'total'         => (float) $fila->t,
            'efectivo'      => $efectivo,
            'tarjeta'       => (float) $fila->ta,
            'transferencia' => (float) $fila->tr,
            'fondo'         => (float) $corte->fondo_inicial,
            'esperado'      => (float) $corte->fondo_inicial + $efectivo,
        ];
    }

    public function confirmarCierre(): void
    {
        if (! is_numeric($this->efectivoContado)) {
            Notification::make()->title('Ingresá el efectivo contado')->warning()->send();

            return;
        }

        $corte = CorteCaja::find($this->corteId);

        if ($corte === null) {
            return;
        }

        $cerrado = app(CorteCajaService::class)->cerrar($corte, (float) $this->efectivoContado, $this->notasCierre ?: null);

        $dif = (float) $cerrado->diferencia;
        $msg = $dif === 0.0 ? 'Caja cuadrada.' : ($dif > 0 ? 'Sobrante de L. '.number_format($dif, 2) : 'Faltante de L. '.number_format(abs($dif), 2));

        Notification::make()->title('Turno cerrado')->body($msg)->{$dif === 0.0 ? 'success' : 'warning'}()->send();

        $this->mostrarCierre = false;
        $this->efectivoContado = '';
        $this->notasCierre = '';
        $this->cargarTurno();
    }

    public function setTipoServicio(string $tipo): void
    {
        $this->tipoServicio = $tipo;
    }

    /**
     * Alterna el aviso de reposición de un complemento: si no estaba bajo,
     * avisa a cocina; si ya estaba avisado, lo quita (ya se repuso).
     */
    public function alternarReposicion(int $productoId): void
    {
        $svc = app(ReposicionService::class);

        if (in_array($productoId, $this->productosBajos, true)) {
            $svc->reponer($productoId, (int) Auth::id());
            $titulo = 'Aviso quitado';
            $cuerpo = 'Marcado como repuesto.';
        } else {
            $svc->alertar($productoId, (int) Auth::id());
            $titulo = 'Aviso enviado a cocina';
            $cuerpo = 'Se solicitó reponer el complemento.';
        }

        $this->productosBajos = $svc->productosConAlerta();

        Notification::make()->title($titulo)->body($cuerpo)->success()->send();
    }

    /** Carga el menú del servicio actual (filtrado por el menú del día). */
    private function cargarMenu(): void
    {
        $productos = app(MenuDiaService::class)->disponibles(now(), $this->servicioId);

        $this->proteinas = $productos->where('categoria', 'proteina')->values()->all();
        $this->complementos = $productos->where('categoria', 'complemento')->values()->all();
        $this->bebidas = $productos->where('categoria', 'bebida')->values()->all();
        $this->extras = $productos->where('categoria', 'extra')->values()->all();
        $this->combos = $productos->where('categoria', 'combo')->values()->all();
    }

    public function cambiarServicio(int $id): void
    {
        $this->servicioId = $id;
        $this->cargarMenu();
    }

    // ── Construcción del plato ──────────────────────────────────────────

    public function seleccionarProteina(int $id): void
    {
        $this->proteinaId = $this->proteinaId === $id ? null : $id;
    }

    public function agregarComplemento(int $id): void
    {
        $this->complementoSel[] = $id;
    }

    public function quitarComplemento(int $id): void
    {
        $pos = array_search($id, $this->complementoSel, true);

        if ($pos !== false) {
            unset($this->complementoSel[$pos]);
            $this->complementoSel = array_values($this->complementoSel);
        }
    }

    public function contarComplemento(int $id): int
    {
        return count(array_filter($this->complementoSel, static fn (int $x): bool => $x === $id));
    }

    public function agregarPlato(): void
    {
        if ($this->proteinaId === null) {
            Notification::make()->title('Seleccioná una proteína primero')->warning()->send();

            return;
        }

        $linea = app(CotizadorVenta::class)->cotizarPlato($this->proteinaId, $this->complementoSel);
        $this->pushLinea($linea, 'plato', $this->cantidadPlato);

        $this->proteinaId = null;
        $this->complementoSel = [];
        $this->cantidadPlato = 1;
    }

    /** Ajusta cuántos platos idénticos se agregarán (mínimo 1). */
    public function cambiarCantidadPlato(int $delta): void
    {
        $this->cantidadPlato = max(1, $this->cantidadPlato + $delta);
    }

    public function agregarProducto(int $id): void
    {
        // Si ese producto suelto ya está en el carrito, solo sumá la cantidad.
        foreach ($this->carrito as $idx => $item) {
            if ($item['tipo'] === 'producto' && (int) $item['producto_id'] === $id) {
                $this->carrito[$idx]['cantidad'] = (int) $item['cantidad'] + 1;

                return;
            }
        }

        $linea = app(CotizadorVenta::class)->cotizarProducto($id);
        $this->pushLinea($linea, 'producto');
    }

    private function pushLinea(LineaVenta $linea, string $tipo, ?int $cantidad = null): void
    {
        $this->carrito[] = [
            'key'         => uniqid('l', true),
            'tipo'        => $tipo,
            'destino'     => 'aqui', // 'aqui' o 'llevar' (solo aplica a orden 'local')
            'producto_id' => $linea->productoId,
            'nombre'      => $linea->nombre,
            'precio'      => $linea->precioUnitario,
            'cantidad'    => $cantidad ?? $linea->cantidad,
            'grava_isv'   => $linea->gravaIsv,
            'detalle'     => $linea->detalle,
        ];
    }

    /** Alterna el destino de una línea entre Aquí y Llevar. */
    public function alternarDestino(string $key): void
    {
        foreach ($this->carrito as $i => $item) {
            if ($item['key'] === $key) {
                $this->carrito[$i]['destino'] = ($item['destino'] ?? 'aqui') === 'aqui' ? 'llevar' : 'aqui';

                return;
            }
        }
    }

    public function quitarLinea(string $key): void
    {
        $this->carrito = array_values(array_filter(
            $this->carrito,
            static fn (array $i): bool => $i['key'] !== $key,
        ));
    }

    /** Ajusta la cantidad de una línea (mínimo 1). Sirve para vender N iguales. */
    public function cambiarCantidad(string $key, int $delta): void
    {
        foreach ($this->carrito as $i => $item) {
            if ($item['key'] === $key) {
                $this->carrito[$i]['cantidad'] = max(1, (int) $item['cantidad'] + $delta);

                return;
            }
        }
    }

    public function limpiar(): void
    {
        $this->carrito = [];
        $this->proteinaId = null;
        $this->complementoSel = [];
        $this->tipoServicio = 'local';
        $this->formaPago = 'efectivo';
        $this->banco = '';
        $this->rtnInput = '';
        $this->nombreInput = '';
        $this->facturaDetallada = false;
        $this->sugerencias = [];
        $this->domNombre = '';
        $this->domTelefono = '';
        $this->domIdentidad = '';
        $this->domDireccion = '';
    }

    /** Valida los datos de domicilio si corresponde. */
    private function domicilioValido(): bool
    {
        if ($this->tipoServicio !== 'domicilio') {
            return true;
        }

        if (trim($this->domTelefono) === '' || trim($this->domDireccion) === '') {
            Notification::make()->title('Faltan datos de domicilio')->body('Teléfono y dirección son obligatorios.')->warning()->send();

            return false;
        }

        return true;
    }

    /**
     * Crea la comanda de cocina con los items que correspondan:
     *  - domicilio: toda la orden.
     *  - local: solo las líneas marcadas "Llevar" (si no hay, no hay comanda).
     */
    private function enviarAComanda(Venta $venta): void
    {
        if ($this->tipoServicio === 'domicilio') {
            $lineas = $this->carrito;
            $tipoComanda = 'domicilio';
            $domicilio = [
                'nombre'    => $this->domNombre,
                'telefono'  => $this->domTelefono,
                'identidad' => $this->domIdentidad,
                'direccion' => $this->domDireccion,
            ];
        } else {
            $lineas = array_filter($this->carrito, static fn (array $i): bool => ($i['destino'] ?? 'aqui') === 'llevar');
            $tipoComanda = 'llevar';
            $domicilio = [];

            if ($lineas === []) {
                return; // todo es para servir aquí: no va a cocina
            }
        }

        $items = array_map(static fn (array $i): array => [
            'nombre'   => $i['nombre'],
            'cantidad' => $i['cantidad'],
            'detalle'  => $i['detalle'] ?? [],
        ], array_values($lineas));

        $comanda = app(ComandaService::class)->crear($venta, $tipoComanda, $items, $domicilio);

        Notification::make()
            ->title('Enviado a cocina')
            ->body("Comanda {$comanda->numero} · ".($tipoComanda === 'domicilio' ? 'Domicilio' : 'Para llevar').' · '.count($items).' plato(s)')
            ->success()
            ->send();
    }

    // ── Totales en vivo ─────────────────────────────────────────────────

    /** @return array<int, LineaVenta> */
    private function lineasDelCarrito(): array
    {
        return array_map(static fn (array $i): LineaVenta => new LineaVenta(
            productoId: (int) $i['producto_id'],
            nombre: (string) $i['nombre'],
            precioUnitario: (float) $i['precio'],
            cantidad: (int) $i['cantidad'],
            gravaIsv: (bool) $i['grava_isv'],
            detalle: $i['detalle'] ?? [],
        ), $this->carrito);
    }

    /** @return array{gravado: float, exento: float, isv: float, total: float} */
    public function getResumenProperty(): array
    {
        if ($this->carrito === []) {
            return ['gravado' => 0.0, 'exento' => 0.0, 'isv' => 0.0, 'total' => 0.0];
        }

        $r = app(CotizadorVenta::class)->resumir($this->lineasDelCarrito());

        return ['gravado' => $r->gravado, 'exento' => $r->exento, 'isv' => $r->isv, 'total' => $r->total];
    }

    // ── Cierre de venta ─────────────────────────────────────────────────

    /** Factura rápida a Consumidor Final (sin pedir RTN, concepto Alimentación). */
    public function facturarConsumidorFinal(): void
    {
        $this->procesarFactura(null, 'Consumidor Final', false);
    }

    /** Abre el modal para facturar con RTN (cuando el cliente lo pide). */
    public function abrirFactura(): void
    {
        if ($this->carrito === []) {
            Notification::make()->title('El carrito está vacío')->warning()->send();

            return;
        }

        // No se limpian rtnInput/nombreInput acá para respetar lo precargado
        // al "Anular y corregir"; se limpian al terminar la venta (limpiar()).
        $this->mostrarFactura = true;
    }

    /** Al escribir el RTN completo, trae el nombre del cliente si ya existe. */
    public function updatedRtnInput(string $value): void
    {
        $rtn = trim($value);

        if (strlen($rtn) === 14) {
            $cliente = Cliente::query()->where('rtn', $rtn)->first();

            if ($cliente !== null) {
                $this->nombreInput = $cliente->nombre;
                $this->sugerencias = [];
            }
        }
    }

    /** Al escribir el nombre (en mayúsculas), sugiere clientes frecuentes. */
    public function updatedNombreInput(string $value): void
    {
        $this->nombreInput = mb_strtoupper($value);
        $busqueda = trim($this->nombreInput);

        $this->sugerencias = mb_strlen($busqueda) >= 2
            ? Cliente::query()
                ->where('nombre', 'ilike', '%'.$busqueda.'%')
                ->orWhere('rtn', 'like', $busqueda.'%')
                ->orderBy('nombre')
                ->limit(6)
                ->get(['rtn', 'nombre'])
                ->toArray()
            : [];
    }

    /** Selecciona un cliente sugerido (rellena RTN + nombre). */
    public function elegirCliente(string $rtn, string $nombre): void
    {
        $this->rtnInput = $rtn;
        $this->nombreInput = $nombre;
        $this->sugerencias = [];
    }

    /** Emite la factura con el RTN ingresado en el modal. */
    public function emitirFactura(): void
    {
        try {
            $rtn = new RTN(trim($this->rtnInput));
        } catch (Throwable) {
            Notification::make()->title('RTN inválido')->body('Debe tener 14 dígitos numéricos.')->danger()->send();

            return;
        }

        if (trim($this->nombreInput) === '') {
            Notification::make()->title('Falta el nombre del cliente')->warning()->send();

            return;
        }

        if ($this->procesarFactura($rtn, trim($this->nombreInput), $this->facturaDetallada)) {
            $this->mostrarFactura = false;
        }
    }

    /**
     * Núcleo de cobro: emite factura SAR (con o sin RTN), imprime, manda a
     * cocina y limpia. Devuelve true si se emitió.
     */
    private function procesarFactura(?RTN $rtn, string $nombre, ?bool $detallada = null): bool
    {
        if ($this->carrito === []) {
            Notification::make()->title('El carrito está vacío')->warning()->send();

            return false;
        }

        if (! $this->turnoAbierto) {
            Notification::make()->title('Abrí el turno de caja primero')->warning()->send();

            return false;
        }

        if (! $this->domicilioValido()) {
            return false;
        }

        if ($this->formaPago === 'transferencia' && trim($this->banco) === '') {
            Notification::make()->title('Elegí el banco de la transferencia')->warning()->send();

            return false;
        }

        try {
            $factura = app(VentaService::class)->registrarFactura(
                $this->lineasDelCarrito(),
                (int) Auth::id(),
                $rtn,
                $nombre,
                $this->formaPago,
                $detallada,
                $this->formaPago === 'transferencia' ? $this->banco : null,
            );
        } catch (RestauranteException $e) {
            Notification::make()
                ->title('No se pudo emitir la factura')
                ->body($e->getMessage().' Verificá que haya un CAI activo.')
                ->danger()
                ->send();

            return false;
        }

        // Imprime directo (iframe oculto), sin abrir pestaña nueva.
        $this->dispatch('imprimir-factura', url: $factura->urlPdf());

        Notification::make()
            ->title('Factura emitida')
            ->body("N° {$factura->numero}  ·  Total L. ".number_format((float) $factura->total, 2).' · Enviando a impresión…')
            ->actions([
                NotificationAction::make('whatsapp')
                    ->label('Enviar por WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url($factura->urlWhatsApp(), shouldOpenInNewTab: true),
            ])
            ->persistent()
            ->success()
            ->send();

        $this->enviarAComanda($factura->venta);
        $this->limpiar();

        return true;
    }
}
