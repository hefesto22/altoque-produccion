<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Exceptions\RestauranteException;
use App\Domain\ValueObjects\ComponenteLinea;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\Cliente;
use App\Models\ComboEspecial;
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

    // ── Personalización de platillo completo ────────────────────────────
    public bool $personalizando = false;

    public ?int $platilloComboId = null;

    public string $platilloNombre = '';

    public float $platilloPrecio = 0.0;

    /** @var array{carne: int, complemento: int, bebida: int} conteos base por slot */
    public array $platilloBase = ['carne' => 0, 'complemento' => 0, 'bebida' => 0];

    /** @var array<int, array{producto_id: int, nombre: string, precio: float, grava_isv: bool, categoria: string}> */
    public array $platilloSel = [];

    public string $platilloNota = '';

    /** Búsqueda para filtrar productos en el modal de personalización. */
    public string $platilloBuscar = '';

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

    /** Costo del viaje del repartidor (domicilio). Interno: NO va en la factura. */
    public string $costoViaje = '';

    /** Si se está cobrando un pedido pendiente con RTN, su id (para el modal). */
    public ?int $cobrandoPendienteId = null;

    /** Despliegue de la lista de pedidos por cobrar (colapsada por defecto). */
    public bool $mostrarPendientes = false;

    /** Pendiente al que se le está eligiendo banco para cobrar por transferencia. */
    public ?int $cobrandoTransferId = null;

    /** Banco elegido para el cobro (tarjeta/transferencia) de un pendiente. */
    public string $cobroBanco = '';

    /** Forma de pago (tarjeta/transferencia) que se está cobrando con banco. */
    public string $cobroFormaPendiente = '';

    /** @var array<int, int> ids de productos con alerta de reposición activa */
    public array $productosBajos = [];

    // ── Turno de caja ───────────────────────────────────────────────────
    public bool $turnoAbierto = false;

    public ?int $corteId = null;

    public ?string $turnoDesde = null;

    public string $fondoInicial = '';

    /** Saldo inicial del terminal POS (tarjeta/transferencias) al abrir. */
    public string $fondoTerminal = '';

    public bool $mostrarApertura = false;

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
            $key = uniqid('l', true);
            $this->carrito[] = [
                'key'          => $key,
                'grupo'        => $key,
                'tipo'         => ! empty($item->detalle) ? 'plato' : 'producto',
                'producto_id'  => $item->producto_id,
                'nombre'       => $item->nombre,
                'precio'       => (float) $item->precio_unitario,
                'precio_lista' => $item->precio_lista !== null ? (float) $item->precio_lista : null,
                'cantidad'     => (int) $item->cantidad,
                'grava_isv'    => (bool) $item->grava_isv,
                'detalle'      => $item->detalle ?? [],
                'nota'         => $item->nota ?? '',
                'componentes'  => $item->componentes ?? [],
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

    /**
     * Quien entrega el fondo abre el turno (gerente/administrador). El
     * cajero sin este permiso ve el aviso de pedirle la apertura al
     * encargado; su turno se abre desde Cortes De Caja a su nombre.
     */
    public function puedeAbrirTurno(): bool
    {
        return Acceso::puede('abrir_turno');
    }

    public function abrirTurno(): void
    {
        abort_unless(Acceso::puede('abrir_turno'), 403);

        $fondo = is_numeric($this->fondoInicial) ? (float) $this->fondoInicial : 0.0;
        $terminal = is_numeric($this->fondoTerminal) ? (float) $this->fondoTerminal : 0.0;

        app(CorteCajaService::class)->abrir((int) Auth::id(), $fondo, $terminal);
        $this->fondoInicial = '';
        $this->fondoTerminal = '';
        $this->mostrarApertura = false;
        $this->cargarTurno();

        Notification::make()->title('Turno abierto')->body('Ya podés cobrar.')->success()->send();
    }

    /**
     * @return array{ventas: int, total: float, efectivo: float, tarjeta: float, transferencia: float,
     *     esperado: float, fondo: float, tarjeta_banco: array<int, array{banco: string, total: float}>,
     *     transfer_banco: array<int, array{banco: string, total: float}>, dom_efectivo: float, dom_viaje_transfer: float}
     */
    public function getResumenTurnoProperty(): array
    {
        $vacio = [
            'ventas'       => 0, 'total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
            'esperado'     => 0, 'fondo' => 0, 'tarjeta_banco' => [], 'transfer_banco' => [],
            'dom_efectivo' => 0, 'dom_viaje_transfer' => 0,
        ];

        $corte = $this->corteId !== null ? CorteCaja::find($this->corteId) : null;

        if ($corte === null) {
            return $vacio;
        }

        $base = Venta::query()->where('corte_caja_id', $corte->id)->where('pagada', true);

        $fila = (clone $base)
            ->selectRaw("count(*) c, coalesce(sum(total),0) t,
                coalesce(sum(total) filter (where forma_pago='efectivo'),0) ef,
                coalesce(sum(total) filter (where forma_pago='tarjeta'),0) ta,
                coalesce(sum(total) filter (where forma_pago='transferencia'),0) tr,
                coalesce(sum(total) filter (where tipo_orden='domicilio' and forma_pago='efectivo'),0) dom_ef,
                coalesce(sum(costo_viaje) filter (where tipo_orden='domicilio' and forma_pago='transferencia'),0) dom_vt")
            ->first();

        $porBanco = static fn (string $forma): array => (clone $base)
            ->where('forma_pago', $forma)->whereNotNull('banco')
            ->selectRaw('banco, sum(total) as total')->groupBy('banco')->orderBy('banco')->get()
            ->map(static fn ($r): array => ['banco' => (string) $r->banco, 'total' => (float) $r->total])
            ->all();

        $efectivo = (float) $fila->ef;

        return [
            'ventas'             => (int) $fila->c,
            'total'              => (float) $fila->t,
            'efectivo'           => $efectivo,
            'tarjeta'            => (float) $fila->ta,
            'transferencia'      => (float) $fila->tr,
            'fondo'              => (float) $corte->fondo_inicial,
            'esperado'           => (float) $corte->fondo_inicial + $efectivo,
            'tarjeta_banco'      => $porBanco('tarjeta'),
            'transfer_banco'     => $porBanco('transferencia'),
            'dom_efectivo'       => (float) $fila->dom_ef,
            'dom_viaje_transfer' => (float) $fila->dom_vt,
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
        // Red de seguridad: si ya había un plato en construcción CON
        // complementos y el cajero toca otra proteína, el plato anterior se
        // agrega solo (no se pierde la venta por olvidar "Agregar plato").
        if ($this->proteinaId !== null && $this->proteinaId !== $id && $this->complementoSel !== []) {
            $this->agregarPlato();
        }

        $this->proteinaId = $this->proteinaId === $id ? null : $id;

        // Al deseleccionar (tocar la misma proteína), limpiar lo armado para
        // que no quede colgado sobre el próximo plato.
        if ($this->proteinaId === null) {
            $this->complementoSel = [];
            $this->cantidadPlato = 1;
        }
    }

    /**
     * Atajo "por cantidad": arma la proteína activa con los primeros N
     * complementos del menú del día y AGREGA el plato al carrito de una,
     * sin tocar "Agregar plato". La cocina recibe los nombres reales.
     */
    public function platoRapido(int $n): void
    {
        if ($this->proteinaId === null) {
            Notification::make()->title('Seleccioná una proteína primero')->warning()->send();

            return;
        }

        $ids = array_map(
            static fn (Producto $c): int => (int) $c->id,
            array_slice($this->complementos, 0, max(0, $n)),
        );

        if (count($ids) < $n) {
            Notification::make()
                ->title('No hay suficientes complementos en el menú del día')
                ->body('Se agregaron los disponibles.')
                ->warning()
                ->send();
        }

        $this->complementoSel = $ids;
        $this->agregarPlato();
    }

    /** Agrega el plato con la proteína activa y SIN complementos, al instante. */
    public function agregarSinComplementos(): void
    {
        if ($this->proteinaId === null) {
            Notification::make()->title('Seleccioná una proteína primero')->warning()->send();

            return;
        }

        $this->complementoSel = [];
        $this->agregarPlato();
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

    // ── Personalización de platillo completo ────────────────────────────

    /** Abre el modal para personalizar un platillo completo (cambiar/agregar/nota). */
    public function personalizarPlatillo(int $comboId): void
    {
        $combo = ComboEspecial::query()->withoutGlobalScopes()->find($comboId);

        if ($combo === null) {
            $this->agregarProducto($comboId);

            return;
        }

        $base = $combo->composicionBase();

        $this->platilloComboId = $combo->id;
        $this->platilloNombre = $combo->nombre;
        $this->platilloPrecio = (float) $combo->precio;
        $this->platilloBase = ['carne' => $base['carne'], 'complemento' => $base['complemento'], 'bebida' => $base['bebida']];
        $this->platilloSel = array_map(static fn (array $d): array => [
            'producto_id' => $d['producto_id'], 'nombre' => $d['nombre'], 'precio' => $d['precio'],
            'grava_isv'   => $d['grava_isv'], 'categoria' => $d['categoria'],
        ], $base['defaults']);
        $this->platilloNota = '';
        $this->personalizando = true;
    }

    /** Agrega un producto a la selección del platillo (para llenar slot o como extra). */
    public function platilloAgregar(int $productoId): void
    {
        $p = Producto::query()->activos()->find($productoId);

        if ($p === null) {
            return;
        }

        $this->platilloSel[] = [
            'producto_id' => $p->id, 'nombre' => $p->nombre, 'precio' => (float) $p->precio,
            'grava_isv'   => (bool) $p->grava_isv, 'categoria' => $p->categoria,
        ];
    }

    public function platilloQuitar(int $index): void
    {
        unset($this->platilloSel[$index]);
        $this->platilloSel = array_values($this->platilloSel);
    }

    /** @return array{carne: int, complemento: int, bebida: int, extras: int, precio_extras: float, total: float, extra_indices: array<int, int>} */
    public function getPlatilloResumenProperty(): array
    {
        $counts = ['carne' => 0, 'complemento' => 0, 'bebida' => 0];
        $extras = 0;
        $precioExtras = 0.0;
        $extraIndices = [];

        // Un pase conservando el índice original: los primeros N por categoría
        // (N = cupo base) son base; el resto es extra (a su precio).
        foreach ($this->platilloSel as $idx => $s) {
            $slot = match ($s['categoria']) {
                'proteina' => 'carne',
                'bebida'   => 'bebida',
                default    => 'complemento',
            };
            $counts[$slot]++;

            if ($counts[$slot] > (int) $this->platilloBase[$slot]) {
                $extras++;
                $precioExtras += (float) $s['precio'];
                $extraIndices[] = $idx;
            }
        }

        return [
            'carne'         => $counts['carne'], 'complemento' => $counts['complemento'], 'bebida' => $counts['bebida'],
            'extras'        => $extras, 'precio_extras' => round($precioExtras, 2),
            'total'         => round($this->platilloPrecio + $precioExtras, 2),
            'extra_indices' => $extraIndices,
        ];
    }

    public function confirmarPlatillo(): void
    {
        if ($this->platilloComboId === null) {
            return;
        }

        $lineas = app(CotizadorVenta::class)->cotizarPlatilloPersonalizado(
            $this->platilloComboId,
            $this->platilloSel,
            trim($this->platilloNota),
        );

        // Platillo + sus extras comparten grupo → se ven juntos en el carrito.
        $grupo = uniqid('g', true);

        foreach ($lineas as $i => $linea) {
            // El primero (base) va como 'plato' (a cocina); los extras como 'producto'.
            $this->pushLinea($linea, $i === 0 ? 'plato' : 'producto', grupo: $grupo);
        }

        $this->cancelarPlatillo();
    }

    public function cancelarPlatillo(): void
    {
        $this->personalizando = false;
        $this->platilloComboId = null;
        $this->platilloNombre = '';
        $this->platilloPrecio = 0.0;
        $this->platilloBase = ['carne' => 0, 'complemento' => 0, 'bebida' => 0];
        $this->platilloSel = [];
        $this->platilloNota = '';
        $this->platilloBuscar = '';
    }

    private function pushLinea(LineaVenta $linea, string $tipo, ?int $cantidad = null, ?string $grupo = null): void
    {
        $key = uniqid('l', true);

        $this->carrito[] = [
            'key'          => $key,
            'grupo'        => $grupo ?? $key,   // singleton por defecto; el platillo comparte grupo con sus extras
            'tipo'         => $tipo,
            'producto_id'  => $linea->productoId,
            'nombre'       => $linea->nombre,
            'precio'       => $linea->precioUnitario,
            'precio_lista' => $linea->precioListaUnitario,
            'cantidad'     => $cantidad ?? $linea->cantidad,
            'grava_isv'    => $linea->gravaIsv,
            'detalle'      => $linea->detalle,
            'nota'         => $linea->nota,
            'componentes'  => array_map(static fn (ComponenteLinea $c): array => $c->toArray(), $linea->componentes),
        ];
    }

    public function quitarLinea(string $key): void
    {
        $this->carrito = array_values(array_filter(
            $this->carrito,
            static fn (array $i): bool => $i['key'] !== $key,
        ));
    }

    /** Quita un platillo completo con todos sus extras (mismo grupo). */
    public function quitarGrupo(string $grupo): void
    {
        $this->carrito = array_values(array_filter(
            $this->carrito,
            static fn (array $i): bool => ($i['grupo'] ?? $i['key']) !== $grupo,
        ));
    }

    /**
     * Carrito agrupado para mostrar: cada platillo con sus extras juntos.
     *
     * @return array<int, array{principal: array<string, mixed>, extras: array<int, array<string, mixed>>, total: float}>
     */
    public function getCarritoAgrupadoProperty(): array
    {
        /** @var array<string, array{principal: array<string, mixed>, extras: array<int, array<string, mixed>>, total: float}> $grupos */
        $grupos = [];

        foreach ($this->carrito as $item) {
            $g = (string) ($item['grupo'] ?? $item['key']);
            $importe = (float) $item['precio'] * (int) $item['cantidad'];

            if (! isset($grupos[$g])) {
                $grupos[$g] = ['principal' => $item, 'extras' => [], 'total' => $importe];
            } else {
                $grupos[$g]['extras'][] = $item;
                $grupos[$g]['total'] += $importe;
            }
        }

        return array_values($grupos);
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
        $this->costoViaje = '';
        $this->cobrandoPendienteId = null;
        $this->cobrandoTransferId = null;
        $this->cobroFormaPendiente = '';
        $this->cobroBanco = '';
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
     * Crea la comanda de cocina según el tipo de orden:
     *  - local: se sirve aquí, NO va a cocina.
     *  - llevar: toda la orden a cocina; el cliente la recoge (nombre opcional).
     *  - domicilio: toda la orden a cocina; la lleva un repartidor (con dirección).
     */
    private function enviarAComanda(Venta $venta): void
    {
        if ($this->tipoServicio === 'local') {
            return; // el buffet servido en el local no genera comanda
        }

        $datos = $this->tipoServicio === 'domicilio'
            ? [
                'nombre'    => $this->domNombre,
                'telefono'  => $this->domTelefono,
                'identidad' => $this->domIdentidad,
                'direccion' => $this->domDireccion,
            ]
            : ['nombre' => $this->domNombre]; // para llevar: solo nombre opcional

        $items = array_map(static fn (array $i): array => [
            'nombre'   => $i['nombre'],
            'cantidad' => $i['cantidad'],
            'detalle'  => $i['detalle'] ?? [],
            'nota'     => $i['nota'] ?? '',
        ], array_values($this->carrito));

        $comanda = app(ComandaService::class)->crear($venta, $this->tipoServicio, $items, $datos);

        Notification::make()
            ->title('Enviado a cocina')
            ->body("Comanda {$comanda->numero} · ".($this->tipoServicio === 'domicilio' ? 'Domicilio' : 'Para llevar').' · '.count($items).' plato(s)')
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
            precioListaUnitario: isset($i['precio_lista']) ? (float) $i['precio_lista'] : null,
            componentes: array_map(
                static fn (array $c): ComponenteLinea => ComponenteLinea::fromArray($c),
                $i['componentes'] ?? [],
            ),
            nota: (string) ($i['nota'] ?? ''),
        ), $this->carrito);
    }

    /**
     * Vista previa del plato en construcción: nombre, cantidad de
     * complementos, precio de combo en vivo y ahorro. Null si no hay
     * proteína seleccionada.
     *
     * @return array{nombre: string, n: int, precio: float, descuento: float}|null
     */
    public function getPlatoPreviewProperty(): ?array
    {
        if ($this->proteinaId === null) {
            return null;
        }

        $linea = app(CotizadorVenta::class)->cotizarPlato($this->proteinaId, $this->complementoSel);

        return [
            'nombre'    => $linea->nombre,
            'n'         => count($this->complementoSel),
            'precio'    => $linea->precioUnitario,
            'descuento' => $linea->descuento(),
        ];
    }

    /** @return array{gravado: float, exento: float, isv: float, total: float, subtotal_lista: float, descuento: float} */
    public function getResumenProperty(): array
    {
        if ($this->carrito === []) {
            return ['gravado' => 0.0, 'exento' => 0.0, 'isv' => 0.0, 'total' => 0.0, 'subtotal_lista' => 0.0, 'descuento' => 0.0];
        }

        $r = app(CotizadorVenta::class)->resumir($this->lineasDelCarrito());

        return [
            'gravado'        => $r->gravado,
            'exento'         => $r->exento,
            'isv'            => $r->isv,
            'total'          => $r->total,
            'subtotal_lista' => $r->subtotalLista,
            'descuento'      => $r->descuento,
        ];
    }

    // ── Cierre de venta ─────────────────────────────────────────────────

    /** Factura rápida a Consumidor Final (sin pedir RTN, concepto Alimentación). */
    public function facturarConsumidorFinal(): void
    {
        $this->procesarFactura(null, 'Consumidor Final', false);
    }

    /** Total que se muestra en el modal: el del pendiente si se cobra uno, si no el del carrito. */
    public function getTotalModalProperty(): float
    {
        if ($this->cobrandoPendienteId !== null) {
            return (float) (Venta::query()->whereKey($this->cobrandoPendienteId)->value('total') ?? 0);
        }

        return $this->getResumenProperty()['total'];
    }

    /** Costo de viaje numérico (solo domicilio); 0 en otros casos. */
    private function costoViajeNumerico(): float
    {
        return $this->tipoServicio === 'domicilio' && is_numeric($this->costoViaje)
            ? round((float) $this->costoViaje, 2)
            : 0.0;
    }

    /**
     * "Pagar después": manda el pedido a cocina como PENDIENTE de pago (sin
     * cobrar ni facturar). Solo para llevar / domicilio. Se cobra luego desde
     * la lista de pendientes.
     */
    public function pagarDespues(): void
    {
        if ($this->tipoServicio === 'local') {
            Notification::make()->title('“Pagar después” es para llevar o domicilio')
                ->body('En el local se cobra al momento.')->warning()->send();

            return;
        }

        if ($this->carrito === [] || ! $this->turnoAbierto || ! $this->domicilioValido()) {
            if ($this->carrito === []) {
                Notification::make()->title('El carrito está vacío')->warning()->send();
            } elseif (! $this->turnoAbierto) {
                Notification::make()->title('Abrí el turno de caja primero')->warning()->send();
            }

            return;
        }

        $venta = app(VentaService::class)->registrarPendiente(
            $this->lineasDelCarrito(),
            (int) Auth::id(),
            $this->tipoServicio,
            $this->formaPago,
            in_array($this->formaPago, ['tarjeta', 'transferencia'], true) ? $this->banco : null,
            $this->costoViajeNumerico(),
            trim($this->domNombre) !== '' ? mb_strtoupper(trim($this->domNombre)) : null,
        );

        $this->enviarAComanda($venta);

        Notification::make()
            ->title("Pedido en cocina · Orden {$venta->numero_orden}")
            ->body('Queda PENDIENTE de pago. Cobralo desde “Pedidos por cobrar” cuando esté listo.')
            ->success()
            ->send();

        $this->limpiar();
    }

    /** @return array<int, Venta> Pedidos pendientes de pago (cualquier cajero: una sola caja). */
    public function getPedidosPendientesProperty(): array
    {
        return Venta::query()
            ->pendientes()
            ->with('items:id,venta_id,nombre,cantidad')
            ->select(['id', 'numero_orden', 'tipo_orden', 'nombre_cliente', 'total', 'costo_viaje', 'forma_pago', 'vendida_at'])
            ->orderBy('vendida_at')
            ->get()
            ->all();
    }

    /** Cobra un pendiente a Consumidor Final (sin RTN) en efectivo (sin banco). */
    public function cobrarPendienteCF(int $ventaId, string $formaPago): void
    {
        $this->ejecutarCobroPendiente($ventaId, null, 'Consumidor Final', $formaPago);
    }

    /** Abre el selector de banco para cobrar un pendiente con tarjeta o transferencia. */
    public function pedirBancoPendiente(int $ventaId, string $forma): void
    {
        $this->cobrandoTransferId = $ventaId;
        $this->cobroFormaPendiente = $forma;
        $this->cobroBanco = '';
    }

    public function cancelarTransferenciaPendiente(): void
    {
        $this->cobrandoTransferId = null;
        $this->cobroFormaPendiente = '';
        $this->cobroBanco = '';
    }

    /** Confirma el cobro (tarjeta o transferencia) con el banco elegido. */
    public function confirmarTransferenciaPendiente(): void
    {
        if ($this->cobrandoTransferId === null) {
            return;
        }

        $ok = $this->ejecutarCobroPendiente(
            $this->cobrandoTransferId,
            null,
            'Consumidor Final',
            $this->cobroFormaPendiente !== '' ? $this->cobroFormaPendiente : 'transferencia',
            false,
            $this->cobroBanco,
        );

        if ($ok) {
            $this->cancelarTransferenciaPendiente();
        }
    }

    /** Abre el modal de RTN para facturar un pendiente con datos del cliente. */
    public function facturarPendienteRtn(int $ventaId): void
    {
        $this->cobrandoPendienteId = $ventaId;
        $this->rtnInput = '';
        $this->nombreInput = '';
        $this->mostrarFactura = true;
    }

    /** Núcleo del cobro de un pendiente: emite el documento y marca pagado. */
    private function ejecutarCobroPendiente(int $ventaId, ?RTN $rtn, string $nombre, string $formaPago, ?bool $detallada = false, ?string $banco = null): bool
    {
        $venta = Venta::pendientes()->find($ventaId);

        if ($venta === null) {
            Notification::make()->title('Ese pedido ya no está pendiente')->warning()->send();

            return false;
        }

        if (! $this->turnoAbierto) {
            Notification::make()->title('Abrí el turno de caja primero')->warning()->send();

            return false;
        }

        if (in_array($formaPago, ['tarjeta', 'transferencia'], true) && trim((string) $banco) === '') {
            Notification::make()->title('Elegí el banco')->warning()->send();

            return false;
        }

        try {
            $factura = app(VentaService::class)->cobrarPendiente(
                $venta,
                (int) Auth::id(),
                $rtn,
                $nombre,
                $formaPago,
                $detallada,
                in_array($formaPago, ['tarjeta', 'transferencia'], true) ? $banco : null,
            );
        } catch (RestauranteException $e) {
            Notification::make()->title('No se pudo cobrar')->body($e->getMessage())->danger()->send();

            return false;
        }

        $this->dispatch('imprimir-factura', url: $factura->urlPdf());

        Notification::make()
            ->title("Cobrado · Orden {$factura->venta->numero_orden}")
            ->body("Factura {$factura->numero} · Total L. ".number_format((float) $factura->total, 2))
            ->success()
            ->send();

        return true;
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
        $this->cobrandoPendienteId = null; // factura del carrito, no de un pendiente
        $this->mostrarFactura = true;
    }

    /** Cierra el modal de factura y limpia el estado de cobro de pendiente. */
    public function cerrarModalFactura(): void
    {
        $this->mostrarFactura = false;
        $this->cobrandoPendienteId = null;
        $this->sugerencias = [];
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

        // Si venimos de "Factura con RTN" de un pendiente, se cobra ese pedido
        // (no el carrito). Si no, es la venta del carrito.
        if ($this->cobrandoPendienteId !== null) {
            $ok = $this->ejecutarCobroPendiente(
                $this->cobrandoPendienteId,
                $rtn,
                trim($this->nombreInput),
                $this->formaPago,
                $this->facturaDetallada,
                $this->banco,
            );

            if ($ok) {
                $this->cobrandoPendienteId = null;
                $this->mostrarFactura = false;
                $this->rtnInput = '';
                $this->nombreInput = '';
            }

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

        if (in_array($this->formaPago, ['tarjeta', 'transferencia'], true) && trim($this->banco) === '') {
            Notification::make()->title('Elegí el banco')->warning()->send();

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
                in_array($this->formaPago, ['tarjeta', 'transferencia'], true) ? $this->banco : null,
                $this->tipoServicio,
                $this->costoViajeNumerico(),
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
            ->title("Factura emitida · Orden {$factura->venta->numero_orden}")
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
