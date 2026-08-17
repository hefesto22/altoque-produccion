<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Exceptions\CuentaNoAmpliableException;
use App\Domain\Exceptions\RestauranteException;
use App\Domain\ValueObjects\ComponenteLinea;
use App\Domain\ValueObjects\LineaVenta;
use App\Domain\ValueObjects\RTN;
use App\Models\BrandingSetting;
use App\Models\Cliente;
use App\Models\Comanda;
use App\Models\ComboEspecial;
use App\Models\CorteCaja;
use App\Models\CuentaPrepago;
use App\Models\EmpresaSetting;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Caja\CorteCajaService;
use App\Services\Cocina\ComandaService;
use App\Services\Cocina\ReposicionService;
use App\Services\Impresion\ColaImpresionService;
use App\Services\Pos\CotizadorVenta;
use App\Services\Pos\MenuDiaService;
use App\Services\Pos\VentaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * Sin título arriba de la página.
     *
     * El cajero ya sabe dónde está (el menú lateral lo marca) y ese bloque se
     * comía la primera franja de pantalla, que en caja es la más cara. El
     * título sigue existiendo para la pestaña del navegador (getTitle) y para
     * el menú lateral (getNavigationLabel).
     */
    public function getHeading(): string
    {
        return '';
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
        return Acceso::puede('View:PuntoDeVenta');
    }

    /**
     * Cuántos tickets esperan salir por la térmica. Solo lo ve quien puede
     * imprimir: un mesero no debe mirar un número sobre el que no puede hacer
     * nada. Ojo, el menú lateral no se refresca con Livewire — el contador
     * vivo es el del panel adentro del POS.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Acceso::puede('ImprimirDirecto')) {
            return null;
        }

        $n = app(ColaImpresionService::class)->contarPendientes();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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

    /**
     * Grupo del carrito que se está EDITANDO (null = platillo nuevo).
     *
     * Editar un platillo ya agregado reabre el mismo modal precargado y, al
     * confirmar, REEMPLAZA ese grupo en su misma posición. Nunca agrega una
     * segunda línea: si lo hiciera, el cliente pagaría dos veces el plato.
     */
    public ?string $platilloEditGrupo = null;

    // ── Modal de factura ────────────────────────────────────────────────
    public bool $mostrarFactura = false;

    public string $rtnInput = '';

    public string $nombreInput = '';

    /** En la factura con RTN: detallar todo o facturar como "Alimentación". */
    public bool $facturaDetallada = false;

    /** @var array<int, array{rtn: string, nombre: string}> sugerencias de clientes */
    public array $sugerencias = [];

    /** Cuenta prepago detectada por el RTN, memo de ESTE request. */
    private ?CuentaPrepago $cuentaMemo = null;

    private bool $cuentaBuscada = false;

    /**
     * Memo del menú para ESTE request (privado: Livewire no lo serializa).
     *
     * El menú dejó de ser estado de la página. Cuando vivía en propiedades
     * públicas —arrays de modelos Eloquent— Livewire lo mandaba y lo traía
     * en cada request y, peor, rehidrataba cada Producto con su propio
     * SELECT: una consulta por producto en CADA toque de botón. Ahora se lee
     * una sola vez por request desde la caché y no viaja en el snapshot.
     *
     * @var array<string, list<array<string, mixed>>>|null
     */
    private ?array $menuMemo = null;

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

    /** Pendiente al que se le está eligiendo banco para cobrar por transferencia. */
    public ?int $cobrandoTransferId = null;

    /** Banco elegido para el cobro por transferencia de un pendiente. */
    public string $cobroBanco = '';

    /** Forma de pago que se está cobrando con banco (solo transferencia). */
    public string $cobroFormaPendiente = '';

    /** Pendiente al que se le está repartiendo el pago entre varios métodos. */
    public ?int $cobrandoMixtoId = null;

    /** Pendiente al que se le está confirmando la anulación. */
    public ?int $anulandoPendienteId = null;

    /**
     * Cuenta ABIERTA a la que se le está agregando (modo AGREGANDO).
     *
     * Mientras esté seteada, el POS no cobra ni manda un pedido nuevo: todo
     * lo que se pique se le SUMA a esa orden y se cobra al final, junto.
     */
    public ?int $agregandoAId = null;

    /** Etiqueta de la cuenta abierta (LOC-2 · MAURICIO) para el banner. */
    public string $agregandoEtiqueta = '';

    /** @var array<int, int> ids de productos con alerta de reposición activa */
    public array $productosBajos = [];

    // ── Turno de caja ───────────────────────────────────────────────────
    public bool $turnoAbierto = false;

    /** Nombre de quien abrió el turno (la caja es una sola). */
    public ?string $turnoDe = null;

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

    // ── Pago mixto: montos por método (solo si formaPago = 'mixto') ────
    public string $mixtoEfectivo = '';

    public string $mixtoTarjeta = '';

    public string $mixtoTransfer = '';

    public function mount(): void
    {
        $this->productosBajos = app(ReposicionService::class)->productosConAlerta();

        $this->cargarTurno();

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
            ->seconds(3)->send();
    }

    private function cargarTurno(): void
    {
        // UNA sola caja física: el turno abierto es del sistema, no de cada
        // usuario. Quien esté en el POS cobra hacia ese turno.
        $corte = app(CorteCajaService::class)->abiertoGlobal();
        $this->turnoAbierto = $corte !== null;
        $this->corteId = $corte?->id;
        $this->turnoDesde = $corte?->abierto_at?->format('d/m/Y h:i A');
        $this->turnoDe = $corte?->cajero?->name;
    }

    /**
     * Quien entrega el fondo abre el turno (gerente/administrador). El
     * cajero sin este permiso ve el aviso de pedirle la apertura al
     * encargado, que la hace desde este mismo POS (único punto de
     * apertura: el turno queda a nombre de quien lo abre).
     */
    public function puedeAbrirTurno(): bool
    {
        return Acceso::puede('AbrirTurno');
    }

    /**
     * Cerrar el turno es cosa de la caja, no del salón. Permiso propio y no
     * `AbrirTurno` porque el cajero NO abre (le entregan el fondo) pero sí es
     * quien cierra todos los días; el mesero con tablet no hace ninguna.
     */
    public function puedeCerrarTurno(): bool
    {
        return Acceso::puede('CerrarTurno');
    }

    /**
     * El dinero se recibe en la CAJA, siempre (regla del negocio, 2026-08-04).
     * El mesero arma el pedido en la tablet y lo manda a cocina; el cliente
     * paga al salir. Sin este permiso el POS esconde todo lo de cobro y solo
     * deja "Pagar después (a cocina)".
     */
    public function puedeCobrar(): bool
    {
        return Acceso::puede('Cobrar');
    }

    /**
     * Agregar a una cuenta abierta es del SALÓN, no de la caja: quien está
     * en la mesa cuando el cliente pide la segunda bebida es el mesero. Por
     * eso es permiso propio y no `Cobrar` — el mesero suma a la cuenta pero
     * sigue sin ver ni tocar el dinero.
     */
    public function puedeAgregarACuenta(): bool
    {
        return Acceso::puede('AgregarACuenta');
    }

    /** Ve la lista de cuentas abiertas quien puede cobrarlas o ampliarlas. */
    public function puedeVerPendientes(): bool
    {
        return $this->puedeCobrar() || $this->puedeAgregarACuenta();
    }

    public function abrirTurno(): void
    {
        abort_unless(Acceso::puede('AbrirTurno'), 403);

        $fondo = is_numeric($this->fondoInicial) ? (float) $this->fondoInicial : 0.0;
        $terminal = is_numeric($this->fondoTerminal) ? (float) $this->fondoTerminal : 0.0;

        $corte = app(CorteCajaService::class)->abrir((int) Auth::id(), $fondo, $terminal);
        $this->fondoInicial = '';
        $this->fondoTerminal = '';
        $this->mostrarApertura = false;
        $this->cargarTurno();

        // Una sola caja: si ya había un turno abierto (de quien sea), no se
        // creó otro — se avisa de quién es y se cobra hacia ese.
        if (! $corte->wasRecentlyCreated) {
            Notification::make()
                ->title('La caja ya tiene un turno abierto')
                ->body('Turno de '.($corte->cajero?->name ?? '—').' abierto desde '.($corte->abierto_at?->format('d/m/Y h:i A') ?? '—').'. Se cobra hacia ese turno.')
                ->warning()
                ->seconds(3)->send();

            return;
        }

        Notification::make()->title('Turno abierto')->body('Ya podés cobrar.')->success()->seconds(3)->send();
    }

    /**
     * @return array{ventas: int, total: float, efectivo: float, tarjeta: float, transferencia: float,
     *     esperado: float, fondo: float, terminal_inicial: float, terminal_final: float,
     *     tarjeta_banco: array<int, array{banco: string, total: float}>,
     *     transfer_banco: array<int, array{banco: string, total: float}>, dom_efectivo: float, dom_viaje_transfer: float}
     */
    public function getResumenTurnoProperty(): array
    {
        $vacio = [
            'ventas'        => 0, 'total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
            'esperado'      => 0, 'fondo' => 0, 'terminal_inicial' => 0, 'terminal_final' => 0,
            'tarjeta_banco' => [], 'transfer_banco' => [],
            'dom_efectivo'  => 0, 'dom_viaje_transfer' => 0,
        ];

        $corte = $this->corteId !== null ? CorteCaja::find($this->corteId) : null;

        if ($corte === null) {
            return $vacio;
        }

        // cuentaEnCaja: excluye pendientes Y ventas con factura anulada.
        $fila = Venta::query()
            ->where('corte_caja_id', $corte->id)->cuentaEnCaja()
            ->selectRaw("count(*) c, coalesce(sum(total),0) t,
                coalesce(sum(costo_viaje) filter (where tipo_orden='domicilio' and forma_pago='transferencia'),0) dom_vt")
            ->first();

        // Por método desde venta_pagos: el pago mixto reparte una venta
        // entre varios métodos y la gaveta solo espera la porción efectivo.
        $pagos = DB::selectOne("
            SELECT
                coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'efectivo'), 0)      AS ef,
                coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'tarjeta'), 0)       AS ta,
                coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'transferencia'), 0) AS tr,
                coalesce(sum(vp.monto) FILTER (WHERE vp.metodo = 'efectivo' AND v.tipo_orden = 'domicilio'), 0) AS dom_ef
            FROM venta_pagos vp
            JOIN ventas v ON v.id = vp.venta_id
            WHERE v.corte_caja_id = ? AND v.pagada = true
              AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id AND f.anulada = true)
        ", [$corte->id]);

        $porBanco = static fn (string $metodo): array => collect(DB::select('
                SELECT vp.banco, sum(vp.monto) AS total
                FROM venta_pagos vp
                JOIN ventas v ON v.id = vp.venta_id
                WHERE v.corte_caja_id = ? AND v.pagada = true
                  AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id AND f.anulada = true)
                  AND vp.metodo = ? AND vp.banco IS NOT NULL
                GROUP BY vp.banco ORDER BY vp.banco
            ', [$corte->id, $metodo]))
            ->map(static fn ($r): array => ['banco' => (string) $r->banco, 'total' => (float) $r->total])
            ->all();

        $efectivo = (float) $pagos->ef;

        return [
            'ventas'             => (int) $fila->c,
            'total'              => (float) $fila->t,
            'efectivo'           => $efectivo,
            'tarjeta'            => (float) $pagos->ta,
            'transferencia'      => (float) $pagos->tr,
            'fondo'              => (float) $corte->fondo_inicial,
            'esperado'           => (float) $corte->fondo_inicial + $efectivo,
            'terminal_inicial'   => (float) $corte->fondo_terminal,
            'terminal_final'     => round((float) $corte->fondo_terminal + (float) $pagos->ta + (float) $pagos->tr, 2),
            'tarjeta_banco'      => $porBanco('tarjeta'),
            'transfer_banco'     => $porBanco('transferencia'),
            'dom_efectivo'       => (float) $pagos->dom_ef,
            'dom_viaje_transfer' => (float) $fila->dom_vt,
        ];
    }

    public function confirmarCierre(): void
    {
        abort_unless(Acceso::puede('CerrarTurno'), 403);

        if (! is_numeric($this->efectivoContado)) {
            Notification::make()->title('Ingresá el efectivo contado')->warning()->seconds(3)->send();

            return;
        }

        $corte = CorteCaja::find($this->corteId);

        if ($corte === null) {
            return;
        }

        $cerrado = app(CorteCajaService::class)->cerrar($corte, (float) $this->efectivoContado, $this->notasCierre ?: null);

        $dif = (float) $cerrado->diferencia;
        $msg = $dif === 0.0 ? 'Caja cuadrada.' : ($dif > 0 ? 'Sobrante de L. '.number_format($dif, 2) : 'Faltante de L. '.number_format(abs($dif), 2));

        Notification::make()->title('Turno cerrado')->body($msg)->{$dif === 0.0 ? 'success' : 'warning'}()->seconds(3)->send();

        // El ticket del corte sale solo en la térmica al cerrar (reimprimible
        // desde Cortes de Caja). Usa la cola de impresión del script global.
        $this->dispatch('imprimir-factura', url: $cerrado->urlTicket());

        $this->mostrarCierre = false;
        $this->efectivoContado = '';
        $this->notasCierre = '';
        $this->cargarTurno();
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

        Notification::make()->title($titulo)->body($cuerpo)->success()->seconds(3)->send();
    }

    /**
     * Color de marca configurado en Sistema → Configuración.
     *
     * Los mosaicos del POS estaban pintados con naranja y rojo fijos, que no
     * tenían nada que ver con el color del panel. Ahora TODOS salen de acá:
     * si mañana se cambia el color en Configuración, el POS cambia solo.
     */
    public function getMarcaColorProperty(): string
    {
        $color = trim((string) BrandingSetting::current()->primary_color);

        // Solo hex de 6 dígitos: el valor entra directo en un atributo style.
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#f59e0b';
    }

    /**
     * Carga TODO el catálogo activo de hoy.
     *
     * Decisión de caja (2026-07-30): el POS ya no filtra por servicio
     * (Desayuno/Almuerzo/Cena). El cajero cobra a cualquier hora lo que el
     * cliente pida y encuentra el producto con el buscador; tener que
     * adivinar el servicio correcto solo escondía productos y frenaba la
     * fila. Por eso se pasa `null` como servicio: `disponibles()` devuelve
     * el catálogo activo completo, sin pasar por `menu_dia`.
     *
     * Lo único que sigue filtrando es la fecha: `disponibleEn()` deja fuera
     * los platos del día de OTRAS fechas (ver PlatoDelDiaService).
     *
     * El Menú del Día NO desaparece: sigue mandando en la pantalla /menu de
     * la TV, que es donde el cliente ve qué hay hoy por servicio.
     */
    /**
     * El menú del día, ya agrupado por sección. Una lectura de caché por
     * request (MenuDiaService::paraElPos) y memo para que da igual cuántas
     * veces lo pida el blade.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function menu(): array
    {
        return $this->menuMemo ??= app(MenuDiaService::class)->paraElPos(now());
    }

    /** @return list<array<string, mixed>> */
    public function getProteinasProperty(): array
    {
        return $this->menu()['proteinas'];
    }

    /** @return list<array<string, mixed>> */
    public function getComplementosProperty(): array
    {
        return $this->menu()['complementos'];
    }

    /** @return list<array<string, mixed>> */
    public function getBebidasProperty(): array
    {
        return $this->menu()['bebidas'];
    }

    /** @return list<array<string, mixed>> */
    public function getExtrasProperty(): array
    {
        return $this->menu()['extras'];
    }

    /** @return list<array<string, mixed>> combos promocionales con nombre */
    public function getCombosProperty(): array
    {
        return $this->menu()['combos'];
    }

    /** @return list<array<string, mixed>> especiales atados solo a la fecha de hoy */
    public function getPlatosDelDiaProperty(): array
    {
        return $this->menu()['platosDelDia'];
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
            Notification::make()->title('Seleccioná una proteína primero')->warning()->seconds(3)->send();

            return;
        }

        // Desde PHP se llama al getter, no a $this->complementos: la
        // propiedad computada es magia de Livewire y el análisis estático
        // no la ve (misma convención que getMixtoSumaProperty()).
        $ids = array_map(
            static fn (array $c): int => (int) $c['id'],
            array_slice($this->getComplementosProperty(), 0, max(0, $n)),
        );

        if (count($ids) < $n) {
            Notification::make()
                ->title('No hay suficientes complementos en el menú del día')
                ->body('Se agregaron los disponibles.')
                ->warning()
                ->seconds(3)->send();
        }

        $this->complementoSel = $ids;
        $this->agregarPlato();
    }

    /** Agrega el plato con la proteína activa y SIN complementos, al instante. */
    public function agregarSinComplementos(): void
    {
        if ($this->proteinaId === null) {
            Notification::make()->title('Seleccioná una proteína primero')->warning()->seconds(3)->send();

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
            Notification::make()->title('Seleccioná una proteína primero')->warning()->seconds(3)->send();

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
        // Si hay un plato a medio armar (proteína elegida) y lo que se toca
        // es una bebida o un extra, el plato se agrega PRIMERO al carrito:
        // la bebida es el cierre natural del pedido y sin esto el plato en
        // construcción se quedaba afuera — riesgo de cobrar solo la bebida.
        // (Un complemento "+ Solo" NO cierra el plato: puede estar a medio elegir.)
        if ($this->proteinaId !== null
            && in_array(Producto::query()->whereKey($id)->value('categoria'), ['bebida', 'extra'], true)) {
            $this->agregarPlato();
        }

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

    /**
     * Reabre el modal para EDITAR un platillo que ya está en el carrito.
     *
     * Se precarga desde el snapshot `seleccion` que guarda la línea principal
     * al agregarse: los `componentes` de la línea no sirven para esto porque
     * guardan nombre/precio (lo fiscal) pero no `producto_id` ni `categoria`,
     * que es lo que el modal necesita para saber qué slot llena cada cosa.
     * Las líneas viejas (o las precargadas por "Anular y corregir") no traen
     * ese snapshot: simplemente no se pueden editar, se quitan y se rehacen.
     */
    public function editarPlatillo(string $grupo): void
    {
        $principal = null;

        foreach ($this->carrito as $item) {
            if ((string) ($item['grupo'] ?? $item['key']) === $grupo && ($item['tipo'] ?? '') === 'plato') {
                $principal = $item;

                break;
            }
        }

        // OJO: `empty()` NO sirve acá. Un platillo con base 0/0 (los PROMO, por
        // ejemplo) se agrega con la selección vacía `[]`, y `empty([])` es true:
        // con empty() esos platillos —justo los que más se venden— quedaban sin
        // poder editarse. Lo que distingue "editable" es que la línea TRAIGA la
        // clave (los productos sueltos la guardan como null).
        if ($principal === null || ! isset($principal['seleccion']) || ! is_array($principal['seleccion'])) {
            return;
        }

        // El cast a int no es cosmético: con `mixed`, find() puede devolver una
        // Collection y Larastan nivel 7 lo rechaza.
        $combo = ComboEspecial::query()->withoutGlobalScopes()->find((int) $principal['producto_id']);

        if ($combo === null) {
            return;
        }

        $base = $combo->composicionBase();

        $this->platilloComboId = $combo->id;
        $this->platilloNombre = $combo->nombre;
        $this->platilloPrecio = (float) $combo->precio;
        $this->platilloBase = ['carne' => $base['carne'], 'complemento' => $base['complemento'], 'bebida' => $base['bebida']];
        /** @var array<int, array{producto_id: int, nombre: string, precio: float, grava_isv: bool, categoria: string}> $sel */
        $sel = array_values($principal['seleccion']);
        $this->platilloSel = $sel;
        $this->platilloNota = (string) ($principal['nota'] ?? '');
        $this->platilloEditGrupo = $grupo;
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
        $grupo = $this->platilloEditGrupo ?? uniqid('g', true);

        // Editando: se conserva la cantidad que ya tenía la línea principal
        // (si el cajero puso 3 iguales, corregir un complemento no los baja a 1).
        $cantidad = null;

        if ($this->platilloEditGrupo !== null) {
            foreach ($this->carrito as $item) {
                if ((string) ($item['grupo'] ?? $item['key']) === $grupo && ($item['tipo'] ?? '') === 'plato') {
                    $cantidad = (int) $item['cantidad'];

                    break;
                }
            }
        }

        $nuevas = [];

        foreach ($lineas as $i => $linea) {
            // El primero (base) va como 'plato' (a cocina); los extras como 'producto'.
            $nuevas[] = $this->construirLinea(
                $linea,
                $i === 0 ? 'plato' : 'producto',
                $i === 0 ? $cantidad : null,
                $grupo,
                $i === 0 ? $this->platilloSel : null,
            );
        }

        if ($this->platilloEditGrupo === null) {
            $this->carrito = array_merge($this->carrito, $nuevas);
        } else {
            // Reemplazo EN SU MISMA POSICIÓN: el carrito no se reordena bajo la
            // mano del cajero por corregir un complemento.
            $resultado = [];
            $puesto = false;

            foreach ($this->carrito as $item) {
                if ((string) ($item['grupo'] ?? $item['key']) === $grupo) {
                    if (! $puesto) {
                        $resultado = array_merge($resultado, $nuevas);
                        $puesto = true;
                    }

                    continue;
                }

                $resultado[] = $item;
            }

            $this->carrito = $puesto ? $resultado : array_merge($resultado, $nuevas);
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
        $this->platilloEditGrupo = null;
    }

    private function pushLinea(LineaVenta $linea, string $tipo, ?int $cantidad = null, ?string $grupo = null): void
    {
        $this->carrito[] = $this->construirLinea($linea, $tipo, $cantidad, $grupo);
    }

    /**
     * Arma la fila del carrito sin agregarla (editar un platillo necesita
     * construir las líneas nuevas antes de decidir dónde van).
     *
     * @param array<int, array<string, mixed>>|null $seleccion snapshot de lo elegido en el modal, solo para poder reabrirlo
     *
     * @return array<string, mixed>
     */
    private function construirLinea(LineaVenta $linea, string $tipo, ?int $cantidad = null, ?string $grupo = null, ?array $seleccion = null): array
    {
        $key = uniqid('l', true);

        return [
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
            // Solo UI: permite reabrir el modal para editar. No entra en la venta.
            'seleccion' => $seleccion,
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
        $this->mixtoEfectivo = '';
        $this->mixtoTarjeta = '';
        $this->mixtoTransfer = '';
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
        $this->cobrandoMixtoId = null;
        $this->anulandoPendienteId = null;
        $this->cobroFormaPendiente = '';
        $this->cobroBanco = '';
        // OJO: el modo AGREGANDO no se toca acá. "Vaciar" borra lo picado
        // pero el mesero sigue parado en la misma cuenta abierta; del modo
        // se sale con "Cancelar" o al agregar con éxito.
    }

    /**
     * Valida los datos del cliente según el tipo de orden (regla de Mauricio):
     *  - llevar:    nombre obligatorio (para llamarlo al recoger).
     *  - domicilio: nombre y teléfono obligatorios; dirección e identidad/RTN
     *               opcionales (la dirección se puede coordinar por teléfono).
     *  - local:     nombre obligatorio SOLO si el pedido va a cocina
     *               ($paraCocina) — sale en la esquina de la comanda para
     *               identificar de quién es. El cobro directo no lo exige.
     */
    private function domicilioValido(bool $paraCocina = false): bool
    {
        if ($paraCocina && $this->tipoServicio === 'local' && trim($this->domNombre) === '') {
            Notification::make()->title('Falta el nombre del cliente')
                ->body('Para mandar a cocina, poné el nombre: sale en la comanda para identificar el pedido.')
                ->warning()->seconds(3)->send();

            return false;
        }

        if ($this->tipoServicio === 'llevar' && trim($this->domNombre) === '') {
            Notification::make()->title('Falta el nombre del cliente')
                ->body('Para llevar, el nombre es obligatorio (para llamarlo cuando esté listo).')
                ->warning()->seconds(3)->send();

            return false;
        }

        if ($this->tipoServicio === 'domicilio'
            && (trim($this->domNombre) === '' || trim($this->domTelefono) === '')) {
            Notification::make()->title('Faltan datos de domicilio')
                ->body('Nombre y teléfono son obligatorios. Dirección e identidad son opcionales.')
                ->warning()->seconds(3)->send();

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
    /**
     * @param bool $incluirLocal ¿Las ventas de local generan comanda? Un
     *                           "pagar después" siempre (la cocina prepara
     *                           con su ticket); el buffet cobrado al momento
     *                           según el flag de Datos de la Empresa.
     * @param bool $entregada La comanda nace ya entregada (local cobrado):
     *                        imprime su ticket pero no ensucia el KDS.
     */
    private function enviarAComanda(Venta $venta, bool $incluirLocal = false, bool $entregada = false, bool $esAmpliacion = false): ?Comanda
    {
        if ($this->tipoServicio === 'local' && ! $incluirLocal) {
            return null; // ventas de local sin comanda (flag desactivado)
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

        $comanda = app(ComandaService::class)->crear($venta, $this->tipoServicio, $items, $datos, $entregada, $esAmpliacion);

        $etiquetaTipo = match ($this->tipoServicio) {
            'domicilio' => 'Domicilio',
            'llevar'    => 'Para llevar',
            default     => 'En el local',
        };

        // El título no puede mentir: desde una tablet la comanda no sale de
        // ninguna impresora, queda esperando a que la caja la saque.
        $imprimeAca = Acceso::puede('ImprimirDirecto');

        $titulo = match (true) {
            ! $imprimeAca => 'Comanda a la cola de la caja',
            $esAmpliacion => 'Agregado enviado a cocina',
            $entregada    => 'Comanda impresa',
            default       => 'Enviado a cocina',
        };

        Notification::make()
            ->title($titulo)
            ->body(($esAmpliacion ? '+ ' : '')."Comanda {$comanda->numero} · {$etiquetaTipo} · ".count($items).' plato(s)')
            ->success()
            ->seconds(3)->send();

        return $comanda;
    }

    // ── Totales en vivo ─────────────────────────────────────────────────

    /**
     * Detalle que ve la caja en la cola. El nombre del cliente va PRIMERO: es
     * lo que identifica el pedido cuando alguien pide que se lo reimpriman.
     */
    private function detalleCola(?string $nombre, string $resto): string
    {
        $nombre = mb_strtoupper(trim((string) $nombre));

        return $nombre !== '' ? $nombre.' · '.$resto : $resto;
    }

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
        abort_unless(Acceso::puede('Cobrar'), 403);

        if ($this->bloqueadoPorAgregar()) {
            return;
        }

        // detallada = null → manda el toggle "Detallar productos por defecto"
        // de Datos de la Empresa. El restaurante pidió que las facturas a
        // Consumidor Final también salgan con el platillo desglosado.
        $this->procesarFactura(null, 'Consumidor Final', null);
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

    // ── Pago mixto ──────────────────────────────────────────────────────

    /** Suma de los montos del pago mixto ingresados hasta ahora. */
    public function getMixtoSumaProperty(): float
    {
        $n = static fn (string $v): float => is_numeric($v) ? round((float) $v, 2) : 0.0;

        return round($n($this->mixtoEfectivo) + $n($this->mixtoTarjeta) + $n($this->mixtoTransfer), 2);
    }

    /** Lo que falta por cubrir del total (negativo = se pasó). */
    public function getMixtoRestanteProperty(): float
    {
        return round($this->getTotalModalProperty() - $this->getMixtoSumaProperty(), 2);
    }

    /**
     * El banco del pago mixto. Los montos se comparten entre el carrito y el
     * cobro de un pendiente, pero el banco vive en campos distintos.
     */
    private function bancoMixto(): string
    {
        return trim($this->cobrandoMixtoId !== null ? $this->cobroBanco : $this->banco);
    }

    /**
     * Filas de pago para el service: null si el cobro es de un solo método.
     * El banco elegido aplica a la porción por tarjeta y transferencia.
     *
     * @param string|null $forma forma de pago a evaluar; null = la del carrito
     *
     * @return array<int, array{metodo: string, banco?: string|null, monto: float}>|null
     */
    private function pagosMixtos(?string $forma = null): ?array
    {
        if (($forma ?? $this->formaPago) !== 'mixto') {
            return null;
        }

        $n = static fn (string $v): float => is_numeric($v) ? round((float) $v, 2) : 0.0;
        $banco = $this->bancoMixto() !== '' ? $this->bancoMixto() : null;

        return [
            ['metodo' => 'efectivo', 'monto' => $n($this->mixtoEfectivo)],
            ['metodo' => 'tarjeta', 'banco' => $banco, 'monto' => $n($this->mixtoTarjeta)],
            ['metodo' => 'transferencia', 'banco' => $banco, 'monto' => $n($this->mixtoTransfer)],
        ];
    }

    /**
     * Valida el pago mixto contra el total antes de cobrar. Notifica si falla.
     *
     * @param string|null $forma forma de pago a evaluar; null = la del carrito
     */
    private function pagoMixtoValido(float $total, ?string $forma = null): bool
    {
        if (($forma ?? $this->formaPago) !== 'mixto') {
            return true;
        }

        $suma = $this->getMixtoSumaProperty();

        if (abs($suma - round($total, 2)) >= 0.01) {
            Notification::make()
                ->title('El pago mixto no cuadra')
                ->body('Los montos suman L. '.number_format($suma, 2).' y el total es L. '.number_format($total, 2).'.')
                ->warning()
                ->seconds(3)->send();

            return false;
        }

        $n = static fn (string $v): float => is_numeric($v) ? round((float) $v, 2) : 0.0;

        if ($n($this->mixtoTransfer) > 0 && $this->bancoMixto() === '') {
            Notification::make()->title('Elegí el banco de la transferencia')->warning()->seconds(3)->send();

            return false;
        }

        return true;
    }

    /**
     * "Pagar después": manda el pedido a cocina como PENDIENTE de pago (sin
     * cobrar ni facturar), en cualquier tipo de orden (local, llevar,
     * domicilio). Imprime el ticket de comanda para cocina; la factura se
     * imprime recién al cobrar desde la lista de pendientes.
     */
    public function pagarDespues(): void
    {
        if ($this->bloqueadoPorAgregar()) {
            return;
        }

        if ($this->carrito === [] || ! $this->turnoAbierto || ! $this->domicilioValido(paraCocina: true)) {
            if ($this->carrito === []) {
                Notification::make()->title('El carrito está vacío')->warning()->seconds(3)->send();
            } elseif (! $this->turnoAbierto) {
                Notification::make()->title('Abrí el turno de caja primero')->warning()->seconds(3)->send();
            }

            return;
        }

        $venta = app(VentaService::class)->registrarPendiente(
            $this->lineasDelCarrito(),
            (int) Auth::id(),
            $this->tipoServicio,
            $this->formaPago,
            $this->formaPago === 'transferencia' ? $this->banco : null,
            $this->costoViajeNumerico(),
            trim($this->domNombre) !== '' ? mb_strtoupper(trim($this->domNombre)) : null,
        );

        $platos = count($this->carrito);
        $comanda = $this->enviarAComanda($venta, incluirLocal: true);

        // El ticket físico de comanda es lo único que llega a la cocina. Sale
        // acá si esta máquina tiene la térmica; si el pedido vino de una
        // tablet, queda en la cola y lo saca la caja.
        $esperaEnCaja = false;

        if ($comanda !== null) {
            $url = app(ColaImpresionService::class)->enviar(
                'comanda',
                (int) $comanda->id,
                "Orden {$venta->numero_orden} · ".$comanda->tipoLabel(),
                $this->detalleCola($venta->nombre_orden, $platos.' plato(s)'),
            );

            if ($url !== null) {
                $this->dispatch('imprimir-comanda', url: $url);
            } else {
                $esperaEnCaja = true;
            }
        }

        Notification::make()
            ->title($esperaEnCaja
                ? "Mandado a caja · Orden {$venta->numero_orden}"
                : "Pedido en cocina · Orden {$venta->numero_orden}")
            ->body($esperaEnCaja
                ? 'La caja imprime la comanda y la pasa a cocina. Queda PENDIENTE de pago.'
                : 'Queda PENDIENTE de pago. Cobralo desde “Pedidos por cobrar” cuando esté listo.')
            ->success()
            ->seconds(3)->send();

        $this->limpiar();
    }

    // ── Agregar a una cuenta abierta ────────────────────────────────────

    /**
     * En modo AGREGANDO el carrito es de una cuenta ajena: cobrarlo o
     * mandarlo aparte partiría en dos lo que el cliente ve como una sola
     * cuenta. Se avisa y no se hace.
     */
    private function bloqueadoPorAgregar(): bool
    {
        if ($this->agregandoAId === null) {
            return false;
        }

        Notification::make()
            ->title('Estás agregando a '.$this->agregandoEtiqueta)
            ->body('Tocá "Agregar a la cuenta" para sumarlo, o "Cancelar" si esto es un pedido aparte.')
            ->warning()->seconds(4)->send();

        return true;
    }

    /**
     * Entra en modo AGREGANDO: lo que se pique se le suma a esta cuenta.
     *
     * El carrito NO se toca — si el mesero ya venía picando la bebida, esas
     * líneas son justo las que va a agregar, y el banner del carrito dice a
     * qué orden van antes de confirmar.
     *
     * El tipo de orden y los datos del cliente se copian de la cuenta: lo
     * agregado viaja igual que lo original (un domicilio no cambia de casa a
     * media entrega) y el ticket de cocina sale con el mismo nombre. El
     * costo de viaje NO se repite: ya se cobró en la orden original.
     */
    public function iniciarAgregar(int $ventaId): void
    {
        abort_unless(Acceso::puede('AgregarACuenta'), 403);

        $venta = Venta::pendientes()->with('comanda')->find($ventaId);

        if ($venta === null) {
            Notification::make()->title('Esa cuenta ya no está abierta')->warning()->seconds(3)->send();

            return;
        }

        $this->agregandoAId = (int) $venta->id;
        // Sin trim() con separador multibyte: se arma condicional para que
        // un nombre raro no termine cortado a media letra en el banner.
        $this->agregandoEtiqueta = ($venta->nombre_orden ?? '') !== ''
            ? $venta->numero_orden.' · '.$venta->nombre_orden
            : $venta->numero_orden;

        // Modales de cobro abiertos: no aplican mientras se agrega.
        $this->cobrandoTransferId = null;
        $this->cobrandoMixtoId = null;
        $this->anulandoPendienteId = null;

        $comanda = $venta->comanda;

        $this->tipoServicio = $venta->tipo_orden;
        $this->domNombre = (string) ($comanda?->cliente_nombre ?: $venta->nombre_orden ?: '');
        $this->domTelefono = (string) ($comanda?->cliente_telefono ?? '');
        $this->domIdentidad = (string) ($comanda?->cliente_identidad ?? '');
        $this->domDireccion = (string) ($comanda?->cliente_direccion ?? '');
        $this->costoViaje = '';
    }

    /** Sale del modo AGREGANDO sin tocar el carrito: lo picado no se pierde. */
    public function cancelarAgregar(): void
    {
        $this->agregandoAId = null;
        $this->agregandoEtiqueta = '';
    }

    /**
     * Suma el carrito a la cuenta abierta y manda a cocina SOLO lo nuevo,
     * marcado como agregado. Acá no se emite nada fiscal: la cuenta se
     * sigue cobrando de una sola vez desde "Pedidos por cobrar".
     */
    public function agregarACuenta(): void
    {
        abort_unless(Acceso::puede('AgregarACuenta'), 403);

        if ($this->agregandoAId === null) {
            return;
        }

        if ($this->carrito === []) {
            Notification::make()->title('No hay nada que agregar')->warning()->seconds(3)->send();

            return;
        }

        if (! $this->domicilioValido(paraCocina: true)) {
            return;
        }

        $venta = Venta::pendientes()->find($this->agregandoAId);

        if ($venta === null) {
            Notification::make()
                ->title('Esa cuenta ya se cerró')
                ->body('La caja la cobró o la anuló mientras tanto. Lo del carrito queda intacto: cobralo como pedido aparte.')
                ->warning()->seconds(6)->send();

            $this->cancelarAgregar();

            return;
        }

        try {
            $venta = app(VentaService::class)->agregarACuenta(
                $venta,
                $this->lineasDelCarrito(),
                (int) Auth::id(),
            );
        } catch (CuentaNoAmpliableException $e) {
            // Perdió la carrera contra el cobro: la cuenta ya se cerró.
            Notification::make()->title('No se pudo agregar')->body($e->getMessage())->danger()->seconds(6)->send();
            $this->cancelarAgregar();

            return;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('No se pudo agregar')
                ->body('Volvé a intentarlo. El carrito no se perdió.')
                ->danger()->seconds(5)->send();

            return;
        }

        $platos = count($this->carrito);
        $comanda = $this->enviarAComanda($venta, incluirLocal: true, esAmpliacion: true);

        // Igual que un pedido nuevo: si esta máquina no tiene térmica, el
        // ticket de lo agregado queda en la cola y lo saca la caja.
        $esperaEnCaja = false;

        if ($comanda !== null) {
            $url = app(ColaImpresionService::class)->enviar(
                'comanda',
                (int) $comanda->id,
                "+ Agregado a {$venta->numero_orden} · ".$comanda->tipoLabel(),
                $this->detalleCola($venta->nombre_orden, $platos.' plato(s)'),
            );

            if ($url !== null) {
                $this->dispatch('imprimir-comanda', url: $url);
            } else {
                $esperaEnCaja = true;
            }
        }

        $total = number_format((float) $venta->total, 2);

        Notification::make()
            ->title("Agregado a {$venta->numero_orden}")
            ->body($esperaEnCaja
                ? "La caja imprime lo agregado y lo pasa a cocina. La cuenta va en L. {$total}."
                : "La cuenta va en L. {$total} y se cobra toda junta al final.")
            ->success()
            ->seconds(4)->send();

        $this->cancelarAgregar();
        $this->limpiar();
    }

    /** @return array<int, Venta> Pedidos pendientes de pago (cualquier cajero: una sola caja). */
    public function getPedidosPendientesProperty(): array
    {
        // Sin permiso de cobrar NI de agregar, la lista entera desaparece
        // del POS (el bloque del blade solo se dibuja cuando hay pendientes).
        if (! $this->puedeVerPendientes()) {
            return [];
        }

        return Venta::query()
            ->pendientes()
            ->with('items:id,venta_id,nombre,cantidad')
            ->select(['id', 'numero_orden', 'tipo_orden', 'nombre_cliente', 'total', 'costo_viaje', 'forma_pago', 'vendida_at'])
            ->orderBy('vendida_at')
            ->get()
            ->all();
    }

    /** Cobra un pendiente a Consumidor Final (sin RTN) sin banco: efectivo o tarjeta. */
    public function cobrarPendienteCF(int $ventaId, string $formaPago): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $this->ejecutarCobroPendiente($ventaId, null, 'Consumidor Final', $formaPago);
    }

    /** Abre el selector de banco para cobrar un pendiente por transferencia. */
    public function pedirBancoPendiente(int $ventaId, string $forma): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $this->cobrandoTransferId = $ventaId;
        $this->cobrandoMixtoId = null;
        $this->anulandoPendienteId = null;
        $this->cobroFormaPendiente = $forma;
        $this->cobroBanco = '';
    }

    /**
     * Abre el reparto de pago de un pendiente entre efectivo, tarjeta y
     * transferencia. Los montos se llevan en los mismos campos del carrito.
     */
    public function pedirMixtoPendiente(int $ventaId): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $this->cobrandoMixtoId = $ventaId;
        $this->cobrandoTransferId = null;
        $this->anulandoPendienteId = null;
        $this->mixtoEfectivo = '';
        $this->mixtoTarjeta = '';
        $this->mixtoTransfer = '';
        $this->cobroBanco = '';
    }

    public function cancelarMixtoPendiente(): void
    {
        $this->cobrandoMixtoId = null;
        $this->mixtoEfectivo = '';
        $this->mixtoTarjeta = '';
        $this->mixtoTransfer = '';
        $this->cobroBanco = '';
    }

    /** Confirma el cobro repartido. La validación vive en ejecutarCobroPendiente. */
    public function confirmarMixtoPendiente(): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        if ($this->cobrandoMixtoId === null) {
            return;
        }

        $ok = $this->ejecutarCobroPendiente(
            $this->cobrandoMixtoId,
            null,
            'Consumidor Final',
            'mixto',
            false,
            $this->cobroBanco,
        );

        if ($ok) {
            $this->cancelarMixtoPendiente();
        }
    }

    public function cancelarTransferenciaPendiente(): void
    {
        $this->cobrandoTransferId = null;
        $this->cobroFormaPendiente = '';
        $this->cobroBanco = '';
    }

    /** Confirma el cobro por transferencia con el banco elegido. */
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

    /**
     * Pide confirmación antes de anular. Anular no es cobrar de menos: es
     * decir que el pedido no se hizo, así que no hay nada que cobrar.
     */
    public function pedirAnularPendiente(int $ventaId): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $this->anulandoPendienteId = $ventaId;
        $this->cobrandoTransferId = null;
        $this->cobrandoMixtoId = null;
    }

    public function cancelarAnulacionPendiente(): void
    {
        $this->anulandoPendienteId = null;
    }

    /** Anula el pedido: sale de "por cobrar", de cocina y de la cola de impresión. */
    public function confirmarAnularPendiente(): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        if ($this->anulandoPendienteId === null) {
            return;
        }

        $venta = Venta::pendientes()->with('comanda')->find($this->anulandoPendienteId);

        if ($venta === null) {
            Notification::make()->title('Ese pedido ya no está pendiente')->warning()->seconds(3)->send();
            $this->anulandoPendienteId = null;

            return;
        }

        try {
            app(VentaService::class)->anularPendiente($venta, (int) Auth::id());
        } catch (RestauranteException $e) {
            Notification::make()->title('No se pudo anular')->body($e->getMessage())->danger()->seconds(3)->send();

            return;
        }

        // Si su comanda seguía esperando papel, ese ticket ya no tiene para qué salir.
        if ($venta->comanda !== null) {
            app(ColaImpresionService::class)->cancelarDe('comanda', (int) $venta->comanda->id);
        }

        Notification::make()
            ->title("Pedido anulado · Orden {$venta->numero_orden}")
            ->body('Sale de pedidos por cobrar, de cocina y de la cola de impresión.')
            ->warning()
            ->seconds(3)->send();

        $this->anulandoPendienteId = null;
    }

    /** Abre el modal de RTN para facturar un pendiente con datos del cliente. */
    public function facturarPendienteRtn(int $ventaId): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $this->cobrandoPendienteId = $ventaId;
        $this->rtnInput = '';
        $this->nombreInput = '';
        $this->mostrarFactura = true;
    }

    /** Núcleo del cobro de un pendiente: emite el documento y marca pagado. */
    private function ejecutarCobroPendiente(int $ventaId, ?RTN $rtn, string $nombre, string $formaPago, ?bool $detallada = null, ?string $banco = null): bool
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        $venta = Venta::pendientes()->find($ventaId);

        if ($venta === null) {
            Notification::make()->title('Ese pedido ya no está pendiente')->warning()->seconds(3)->send();

            return false;
        }

        if (! $this->turnoAbierto) {
            Notification::make()->title('Abrí el turno de caja primero')->warning()->seconds(3)->send();

            return false;
        }

        if ($formaPago === 'transferencia' && trim((string) $banco) === '') {
            Notification::make()->title('Elegí el banco')->warning()->seconds(3)->send();

            return false;
        }

        if ($formaPago === 'mixto' && ! $this->pagoMixtoValido((float) $venta->total, $formaPago)) {
            return false;
        }

        // El pendiente es el caso NORMAL de una empresa con cuenta: piden,
        // comen y pagan al final. Igual que en el carrito, esto NO factura:
        // el pedido pasa de recibo pendiente a consumo de cuenta.
        $cuenta = $this->cuentaDeCobro($formaPago, (float) $venta->total);

        if ($cuenta === false) {
            return false;
        }

        if ($cuenta !== null) {
            return $this->cobrarPendienteConSaldo($venta, $cuenta);
        }

        try {
            $factura = app(VentaService::class)->cobrarPendiente(
                $venta,
                (int) Auth::id(),
                $rtn,
                $nombre,
                $formaPago,
                $detallada,
                $formaPago === 'transferencia' ? $banco : null,
                $formaPago === 'mixto' ? $this->pagosMixtos($formaPago) : null,
            );
        } catch (RestauranteException $e) {
            Notification::make()->title('No se pudo cobrar')->body($e->getMessage())->danger()->seconds(3)->send();

            return false;
        }

        // Fuera de la transacción y a prueba de fallos: la venta ya quedó
        // registrada y el correlativo SAR consumido — la impresión no puede
        // tumbarla ni empujar a re-facturar (eso quemaría otro correlativo).
        $url = app(ColaImpresionService::class)->enviar(
            'factura',
            (int) $factura->id,
            "Factura {$factura->numero} · Orden {$factura->venta->numero_orden}",
            $this->detalleCola($factura->venta->nombre_orden, 'L. '.number_format((float) $factura->total, 2)),
        );

        if ($url !== null) {
            $this->dispatch('imprimir-factura', url: $url); // HTML: impresión instantánea, sin Chromium
        }

        Notification::make()
            ->title("Cobrado · Orden {$factura->venta->numero_orden}")
            ->body("Factura {$factura->numero} · Total L. ".number_format((float) $factura->total, 2)
                .($url === null ? ' · lo imprime la caja' : ''))
            ->success()
            ->seconds(3)->send();

        return true;
    }

    /** Abre el modal para facturar con RTN (cuando el cliente lo pide). */
    public function abrirFactura(): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        if ($this->bloqueadoPorAgregar()) {
            return;
        }

        if ($this->carrito === []) {
            Notification::make()->title('El carrito está vacío')->warning()->seconds(3)->send();

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

    /**
     * Cuenta prepago del RTN que se está facturando, si existe y está activa.
     *
     * El RTN es la llave: si la empresa dejó dinero adelantado, la caja no
     * tiene que buscarla en ningún lado — se entera sola al facturar.
     *
     * Es propiedad computada y NO estado: el saldo cambia con cada consumo y
     * una copia guardada en la página mostraría un número viejo.
     */
    public function getCuentaSaldoProperty(): ?CuentaPrepago
    {
        if ($this->cuentaBuscada) {
            return $this->cuentaMemo;
        }

        $this->cuentaBuscada = true;
        $this->cuentaMemo = null;
        $rtn = trim($this->rtnInput);

        if (strlen($rtn) !== 14) {
            return null;
        }

        $this->cuentaMemo = CuentaPrepago::query()
            ->activas()
            ->with('cliente')
            ->whereHas('cliente', static fn ($q) => $q->where('rtn', $rtn))
            ->first();

        return $this->cuentaMemo;
    }

    /**
     * Cuenta contra la que se cobra, ya validada.
     *
     * Devuelve null cuando no se cobra con saldo y false cuando se eligió
     * saldo pero no se puede (ya se le avisó al cajero en pantalla).
     *
     * Recibe la forma de pago como parámetro en vez de leer $this->formaPago:
     * el cobro rápido de un pendiente manda la suya y no puede terminar
     * descontándole a una cuenta por un 'saldo' que quedó en la página.
     */
    private function cuentaDeCobro(string $formaPago, float $total): CuentaPrepago|false|null
    {
        if ($formaPago !== 'saldo') {
            return null;
        }

        $cuenta = $this->getCuentaSaldoProperty();

        if ($cuenta === null) {
            Notification::make()
                ->title('Ese RTN no tiene cuenta con saldo')
                ->body('Escribí el RTN de la empresa o cobrá con otra forma de pago.')
                ->warning()->seconds(4)->send();

            return false;
        }

        if (! $cuenta->alcanzaPara($total)) {
            Notification::make()
                ->title('No alcanza el saldo')
                ->body($cuenta->nombre.' tiene disponible L. '.number_format($cuenta->disponible(), 2)
                    .' y la venta es de L. '.number_format($total, 2).'. Registrá un depósito o cobrá de otra forma.')
                ->warning()->seconds(6)->send();

            return false;
        }

        return $cuenta;
    }

    /**
     * Deja la forma de pago en "Saldo" sola cuando el RTN tiene cuenta y alcanza.
     *
     * Detectar la cuenta y no usarla no sirve de nada: el recuadro verde sale
     * con los L. 10,000 de la empresa, el cajero cobra en efectivo por
     * costumbre y el saldo del cliente queda intacto. Si el RTN cambia a uno
     * sin cuenta vuelve a efectivo, para que no quede un "Saldo" pegado de la
     * venta anterior.
     *
     * Solo pisa 'efectivo' (el valor por defecto): si el cajero ya eligió
     * tarjeta o transferencia a mano, esa decisión manda.
     */
    private function ajustarFormaPagoPorSaldo(): void
    {
        // El RTN cambió: lo que se buscó antes en esta misma petición ya no vale.
        $this->cuentaBuscada = false;
        $this->cuentaMemo = null;

        $cuenta = $this->getCuentaSaldoProperty();
        $cubre = $cuenta !== null && $cuenta->alcanzaPara($this->getTotalModalProperty());

        if ($cubre && in_array($this->formaPago, ['efectivo', 'saldo'], true)) {
            $this->formaPago = 'saldo';

            return;
        }

        if (! $cubre && $this->formaPago === 'saldo') {
            $this->formaPago = 'efectivo';
        }
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

        $this->ajustarFormaPagoPorSaldo();
    }

    /**
     * Al escribir el nombre, sugiere clientes frecuentes.
     *
     * ⚠️ NO reasignar aquí $this->nombreInput. Livewire devuelve el valor al
     * input, y como entre la tecla y la respuesta pasa ~1 segundo (debounce +
     * viaje al servidor), lo que el cajero escribió mientras tanto se pisaba:
     * ese era el "escribo y no aparece lo que escribo" que reportó el local.
     * El campo ya se ve en mayúsculas por CSS (text-transform) y se normaliza
     * al emitir la factura.
     *
     * Se piden 3 letras (antes 2) para no disparar búsquedas de más.
     */
    public function updatedNombreInput(string $value): void
    {
        $busqueda = trim($value);

        $this->sugerencias = mb_strlen($busqueda) >= 3
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

        // Se llena el RTN por código: updatedRtnInput() NO corre acá.
        $this->ajustarFormaPagoPorSaldo();
    }

    /** Emite la factura con el RTN ingresado en el modal. */
    public function emitirFactura(): void
    {
        abort_unless(Acceso::puede('Cobrar'), 403);

        try {
            $rtn = new RTN(trim($this->rtnInput));
        } catch (Throwable) {
            Notification::make()->title('RTN inválido')->body('Debe tener 14 dígitos numéricos.')->danger()->seconds(3)->send();

            return;
        }

        if (trim($this->nombreInput) === '') {
            Notification::make()->title('Falta el nombre del cliente')->warning()->seconds(3)->send();

            return;
        }

        // Si venimos de "Factura con RTN" de un pendiente, se cobra ese pedido
        // (no el carrito). Si no, es la venta del carrito.
        if ($this->cobrandoPendienteId !== null) {
            $ok = $this->ejecutarCobroPendiente(
                $this->cobrandoPendienteId,
                $rtn,
                mb_strtoupper(trim($this->nombreInput)),
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

        if ($this->procesarFactura($rtn, mb_strtoupper(trim($this->nombreInput)), $this->facturaDetallada)) {
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
            Notification::make()->title('El carrito está vacío')->warning()->seconds(3)->send();

            return false;
        }

        if (! $this->turnoAbierto) {
            Notification::make()->title('Abrí el turno de caja primero')->warning()->seconds(3)->send();

            return false;
        }

        if (! $this->domicilioValido()) {
            return false;
        }

        if ($this->formaPago === 'transferencia' && trim($this->banco) === '') {
            Notification::make()->title('Elegí el banco')->warning()->seconds(3)->send();

            return false;
        }

        if (! $this->pagoMixtoValido($this->getResumenProperty()['total'])) {
            return false;
        }

        // Cobro contra la cuenta prepago del cliente.
        $cuenta = $this->cuentaDeCobro($this->formaPago, $this->getResumenProperty()['total']);

        if ($cuenta === false) {
            return false;
        }

        // Consumo contra cuenta prepago: NO se factura. Ese dinero ya se
        // facturó el día del depósito; volver a facturarlo declararía dos
        // veces el mismo lempira. Sale nota de consumo, no factura.
        if ($cuenta !== null) {
            return $this->procesarConsumo($cuenta);
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
                $this->tipoServicio,
                $this->costoViajeNumerico(),
                $this->pagosMixtos(),
                trim($this->domNombre) !== '' ? $this->domNombre : null,
            );
        } catch (RestauranteException $e) {
            Notification::make()
                ->title('No se pudo emitir la factura')
                ->body($e->getMessage().' Verificá que haya un CAI activo.')
                ->danger()
                ->seconds(3)->send();

            return false;
        }

        // Factura + comanda salen en UNA sola ventana de impresión como
        // documento de dos páginas (la térmica corta entre una y otra).
        // El local también saca comanda (lo pidió el negocio, configurable
        // en Datos de la Empresa), pero nace ENTREGADA: imprime su ticket
        // sin aparecer en el KDS de cocina.
        $esLocal = $this->tipoServicio === 'local';
        $comanda = $this->enviarAComanda(
            $factura->venta,
            incluirLocal: EmpresaSetting::actual()->imprimeComandaEnLocal(),
            entregada: $esLocal,
        );

        // Idem: después de la transacción y sin poder lanzar. Si esta máquina
        // no tiene térmica, el documento queda en la cola de la caja.
        $url = app(ColaImpresionService::class)->enviar(
            $comanda !== null ? 'documentos' : 'factura',
            (int) $factura->id,
            "Factura {$factura->numero} · Orden {$factura->venta->numero_orden}",
            $this->detalleCola($factura->venta->nombre_orden, 'L. '.number_format((float) $factura->total, 2)),
        );

        if ($url !== null) {
            $this->dispatch('imprimir-factura', url: $url);
        }

        Notification::make()
            ->title("Factura emitida · Orden {$factura->venta->numero_orden}")
            ->body("N° {$factura->numero}  ·  Total L. ".number_format((float) $factura->total, 2)
                .($url === null ? ' · la imprime la caja' : ' · Enviando a impresión…'))
            ->actions([
                NotificationAction::make('whatsapp')
                    ->label('Enviar por WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url($factura->urlWhatsApp(), shouldOpenInNewTab: true),
            ])
            ->success()
            ->seconds(3)->send();

        $this->limpiar();

        return true;
    }

    /**
     * Consumo contra cuenta prepago: descuenta, manda a cocina e imprime la
     * nota NO fiscal. No emite factura ni quema correlativo — ese dinero ya
     * se facturó cuando la empresa hizo su depósito.
     */
    private function procesarConsumo(CuentaPrepago $cuenta): bool
    {
        try {
            $venta = app(VentaService::class)->registrarConsumo(
                $this->lineasDelCarrito(),
                (int) Auth::id(),
                $cuenta,
                $this->tipoServicio,
                $this->costoViajeNumerico(),
                trim($this->domNombre) !== '' ? $this->domNombre : null,
            );
        } catch (RestauranteException $e) {
            Notification::make()
                ->title('No se pudo cargar a la cuenta')
                ->body($e->getMessage())
                ->danger()->seconds(4)->send();

            return false;
        }

        $this->enviarAComanda(
            $venta,
            incluirLocal: EmpresaSetting::actual()->imprimeComandaEnLocal(),
            entregada: $this->tipoServicio === 'local',
        );

        $this->imprimirNotaConsumo($venta, $cuenta);
        $this->limpiar();

        return true;
    }

    /** Cobra un pendiente contra la cuenta: tampoco factura. */
    private function cobrarPendienteConSaldo(Venta $venta, CuentaPrepago $cuenta): bool
    {
        try {
            $consumo = app(VentaService::class)->cobrarPendienteConSaldo($venta, $cuenta, (int) Auth::id());
        } catch (RestauranteException $e) {
            Notification::make()
                ->title('No se pudo cargar a la cuenta')
                ->body($e->getMessage())
                ->danger()->seconds(4)->send();

            return false;
        }

        $this->imprimirNotaConsumo($consumo, $cuenta);

        $this->cobrandoPendienteId = null;
        $this->mostrarFactura = false;
        $this->rtnInput = '';
        $this->nombreInput = '';

        return true;
    }

    /** Encola la nota de consumo y avisa cuánto le quedó a la cuenta. */
    private function imprimirNotaConsumo(Venta $venta, CuentaPrepago $cuenta): void
    {
        $url = app(ColaImpresionService::class)->enviar(
            'nota_consumo',
            (int) $venta->id,
            "Nota de consumo · Orden {$venta->numero_orden}",
            $this->detalleCola($venta->nombre_orden, 'L. '.number_format((float) $venta->total, 2)),
        );

        if ($url !== null) {
            $this->dispatch('imprimir-factura', url: $url);
        }

        $saldo = (float) $cuenta->fresh()->saldo;

        Notification::make()
            ->title("Cargado a {$cuenta->nombre} · Orden {$venta->numero_orden}")
            ->body('Consumió L. '.number_format((float) $venta->total, 2)
                .' · Le queda L. '.number_format($saldo, 2)
                .($saldo < 0 ? ' — EN ROJO, avisale al cliente' : '')
                .($url === null ? ' · la nota la imprime la caja' : ''))
            ->success()
            ->seconds(4)->send();
    }
}
