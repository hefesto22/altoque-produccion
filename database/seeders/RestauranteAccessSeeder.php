<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles, permisos y usuarios del restaurante — ÚNICA fuente de verdad
 * inicial de la matriz de acceso.
 *
 * Todos los permisos usan la convención de Filament Shield con la config
 * de este proyecto (case=pascal, separator=':'): `ViewAny:Venta`,
 * `Update:Producto`, `View:PuntoDeVenta`. Así las pestañas Recursos,
 * Páginas y Permisos personalizados de la pantalla de Roles reflejan y
 * gobiernan la realidad: crear un rol nuevo es tildar casillas, sin código.
 *
 * Este seeder siembra la matriz INICIAL de cada rol (decisión de negocio
 * confirmada con Mauricio). Re-correrlo RESETEA los roles a esta matriz
 * — pisa ajustes manuales hechos en la pantalla de Roles.
 *
 * El super_admin se re-sincroniza aquí con TODOS los permisos: como Shield
 * corre con define_via_gate=false, su acceso depende de tener cada permiso
 * en la base. Sin esto, un permiso nuevo lo dejaría fuera (lock-out).
 */
class RestauranteAccessSeeder extends Seeder
{
    private const PASSWORD_DEV = '12345678';

    /**
     * Acciones de policy que Shield genera por Resource (config
     * filament-shield.policies.methods, en PascalCase).
     *
     * @var array<int, string>
     */
    private const ACCIONES = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore',
        'ForceDelete', 'ForceDeleteAny', 'RestoreAny', 'Replicate', 'Reorder',
    ];

    /**
     * Modelos con Resource en el panel (subject=model → class_basename).
     *
     * @var array<int, string>
     */
    private const MODELOS = [
        'Activity', 'Cai', 'Cliente', 'Combo', 'ComboEspecial', 'Compra',
        'CorteCaja', 'Cotizacion', 'CuentaPrepago', 'EventoArticulo',
        'PedidoOnline', 'PeriodoFiscal', 'Producto', 'Tier', 'User', 'Venta',
    ];

    /**
     * Páginas custom del panel (subject=class, prefix=view → `View:Pagina`).
     *
     * @var array<int, string>
     */
    private const PAGINAS = [
        'BandejaPedidos', 'BrandingSettingsPage', 'Cocina', 'DatosEmpresaPage',
        'DeclaracionIsvMensual', 'LibrosFiscales', 'MenuDelDia', 'PuntoDeVenta',
    ];

    /**
     * Permisos del dominio que no mapean a Resource/Page. Se etiquetan en
     * config/filament-shield.php → custom_permissions.
     *
     * @var array<int, string>
     */
    private const PERMISOS_EXTRA = [
        'ExportVentas',   // descargar reporte del contador
        'VerCortesTodos', // ver cortes de otros cajeros (supervisión)
        'AbrirTurno',     // abrir turno de caja (quien entrega el fondo)
        'AnularFactura',  // anular factura SAR (queda registrada, no se borra)
        'CorregirPago',   // corregir forma de pago de una venta (control interno, auditado)
        'FacturarEvento', // emitir la factura SAR de un evento desde su cotización
    ];

    /**
     * Permisos que NO son de todos: se asignan rol por rol más abajo.
     *
     * `VerAvisoPago` es el recordatorio del pago mensual del sistema — lo ve
     * solo el gerente, que es quien paga. No entra en PERMISOS_EXTRA porque
     * ese bloque se reparte a administrador y gerente por igual.
     *
     * @var array<int, string>
     */
    private const PERMISOS_PROPIOS = [
        'VerAvisoPago',
    ];

    /**
     * Permisos de la CAJA FÍSICA: los tiene quien opera la computadora donde
     * está conectada la única impresora térmica. Las tablets del salón no.
     *
     * `ImprimirDirecto` decide dónde sale el papel: con el permiso el ticket
     * imprime en el acto (comportamiento de siempre); sin él, el trabajo cae
     * a la cola de impresión y lo saca la caja desde el POS.
     *
     * `CerrarTurno` tapa un agujero previo: cerrar el turno no pedía ningún
     * permiso, así que cualquiera con el POS abierto podía cerrar la caja. Es
     * un permiso NUEVO y no el `AbrirTurno` existente porque el cajero no
     * tiene AbrirTurno (lo abre quien entrega el fondo) pero sí es quien
     * cierra todos los días.
     *
     * `Cobrar` es la regla del negocio (2026-08-04): el dinero se recibe en
     * la caja, siempre. El mesero arma el pedido y lo manda a cocina; el
     * cliente paga al salir. Sin este permiso el POS no muestra "Cobrar y
     * Facturar", ni "Factura con RTN", ni la forma de pago, ni la lista de
     * pedidos por cobrar — solo queda "Pagar después (a cocina)".
     *
     * @var array<int, string>
     */
    private const PERMISOS_CAJA = [
        'ImprimirDirecto',
        'CerrarTurno',
        'Cobrar',
    ];

    /**
     * Permisos del SALÓN: los tiene quien atiende la mesa, tenga o no caja.
     *
     * `AgregarACuenta` (2026-08-15) resuelve el caso real: el cliente ya
     * pidió, la comanda ya salió a cocina, y a los diez minutos pide otra
     * bebida. Con este permiso se le SUMA a la misma cuenta (una sola
     * factura al final) en vez de abrir un pedido aparte. Es propio y no
     * `Cobrar` a propósito: el mesero agrega pero sigue sin tocar dinero —
     * en su POS la lista de cuentas abiertas aparece solo con "+ Agregar",
     * sin los botones de cobro.
     *
     * @var array<int, string>
     */
    private const PERMISOS_SALON = [
        'AgregarACuenta',
    ];

    /**
     * Nombres viejos reemplazados por la convención Shield. Se eliminan de
     * la base en cada corrida (idempotente).
     *
     * @var array<int, string>
     */
    private const PERMISOS_OBSOLETOS = [
        // custom en snake (renombrados a PascalCase)
        'anular_factura', 'export_ventas', 'view_cortes_todos', 'abrir_turno',
        // dominio en snake (reemplazados por Accion:Modelo)
        'view_any_producto', 'view_producto', 'create_producto', 'update_producto', 'delete_producto',
        'view_any_venta', 'view_venta', 'create_venta',
        'view_any_factura', 'view_factura', 'create_factura',
        'view_any_cai', 'view_cai', 'create_cai', 'update_cai',
        'view_any_corte_caja', 'view_corte_caja', 'create_corte_caja',
        // página en convención vieja
        'page_PuntoDeVenta',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISOS_OBSOLETOS)->delete();

        $this->crearPermisos();

        /*
         * ── Matriz por rol (decisión de negocio, no técnica) ─────────────
         * administrador: gestiona menú, precios, clientes, compras, CAI y
         *   corrige cortes. NO ve el Registro de Actividad (solo super_admin)
         *   ni gestiona usuarios/roles (solo super_admin).
         * gerente: igual que administrador en lo operativo, sin CAI ni
         *   corrección de cortes ni Datos de la Empresa.
         * cajero: opera el POS, cocina y bandeja; ve ventas y su corte.
         * mesero: SOLO el POS, desde la tablet del salón. Arma el pedido, lo
         *   manda a cocina y le agrega a una cuenta ya abierta (la segunda
         *   bebida de la mesa), nada más: no cobra (el dinero se recibe en la
         *   caja), no imprime (no hay térmica en la tablet: sus comandas van
         *   a la cola y las saca la caja), no cierra turno y no entra al
         *   histórico de ventas.
         * contador: solo lectura fiscal + export.
         */

        $this->rol('administrador', [
            ...$this->crud('Producto'),
            ...$this->crud('Tier'),
            ...$this->crud('Combo'),
            ...$this->crud('ComboEspecial'),
            ...$this->crud('Cliente'),
            ...$this->crud('Compra'),
            ...$this->crud('Cotizacion'), // cotizaciones de eventos (no fiscal)
            ...$this->crud('CuentaPrepago'), // cuentas con saldo a favor
            ...$this->crud('EventoArticulo'), // catálogo propio de eventos
            ...$this->lectura('Venta'), // las ventas nacen del POS y no se editan
            'ViewAny:Cai', 'View:Cai', 'Create:Cai', 'Update:Cai', // sin Delete: un rango CAI no se borra
            'ViewAny:CorteCaja', 'View:CorteCaja', 'Update:CorteCaja', // Update = acción "corregir"
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            'ViewAny:PeriodoFiscal', 'View:PeriodoFiscal', 'Create:PeriodoFiscal', 'Update:PeriodoFiscal',
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina', 'View:MenuDelDia',
            'View:DeclaracionIsvMensual', 'View:LibrosFiscales', 'View:DatosEmpresaPage',
            ...self::PERMISOS_EXTRA,
            ...self::PERMISOS_CAJA, // imprime en la térmica y cierra turno
            ...self::PERMISOS_SALON,
        ]);

        $this->rol('gerente', [
            ...$this->crud('Producto'),
            ...$this->crud('Tier'),
            ...$this->crud('Combo'),
            ...$this->crud('ComboEspecial'),
            ...$this->crud('Cliente'),
            ...$this->crud('Compra'),
            ...$this->crud('Cotizacion'), // cotizaciones de eventos (no fiscal)
            ...$this->crud('CuentaPrepago'), // cuentas con saldo a favor
            ...$this->crud('EventoArticulo'), // catálogo propio de eventos
            ...$this->lectura('Venta'),
            ...$this->lectura('Cai'),
            ...$this->lectura('CorteCaja'), // sin Update: corregir cortes es del administrador
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            ...$this->lectura('PeriodoFiscal'),
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina', 'View:MenuDelDia',
            'View:DeclaracionIsvMensual', 'View:LibrosFiscales',
            ...self::PERMISOS_EXTRA, // incluye AnularFactura (decisión 2026-07-03)
            ...self::PERMISOS_PROPIOS, // aviso del pago mensual: solo el gerente
            ...self::PERMISOS_CAJA,
            ...self::PERMISOS_SALON,
        ]);

        $this->rol('cajero', [
            // Recibe depósitos y consulta el saldo de la empresa que viene a
            // comer, pero no abre ni borra cuentas: eso es de la gerencia.
            'ViewAny:CuentaPrepago', 'View:CuentaPrepago', 'Update:CuentaPrepago',
            ...$this->lectura('Venta'),
            ...$this->lectura('CorteCaja'), // ve su corte; VerCortesTodos amplía a todos
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina',
            ...self::PERMISOS_CAJA, // está sentado en la compu de la impresora
            ...self::PERMISOS_SALON, // también le agrega a una cuenta abierta
        ]);

        /*
         * mesero: la tablet del salón. Solo el POS y solo para mandar pedidos
         * a cocina. Sin Cobrar no ve nada de dinero; sin ImprimirDirecto sus
         * comandas caen a la cola y las saca la caja; sin CerrarTurno no
         * puede cerrar la caja desde una mesa; sin lectura de Venta no entra
         * al histórico.
         *
         * OJO al crear el usuario: además del rol `mesero` necesita
         * `panel_user`, si no canAccessPanel() le rebota el login.
         */
        $this->rol('mesero', [
            'View:PuntoDeVenta',
            ...self::PERMISOS_SALON, // suma a una cuenta abierta; sigue sin cobrar
        ]);

        $this->rol('contador', [
            ...$this->lectura('Venta'),
            ...$this->lectura('CorteCaja'),
            ...$this->lectura('Compra'),
            ...$this->lectura('Cliente'),
            ...$this->lectura('PeriodoFiscal'),
            'View:DeclaracionIsvMensual', 'View:LibrosFiscales',
            'ExportVentas',
        ]);

        // Anti lock-out: el super_admin depende de tener TODO en la base.
        $superAdmin = Role::firstOrCreate(
            ['name' => Utils::getSuperAdminName()],
            ['guard_name' => 'web'],
        );
        $superAdmin->syncPermissions(Permission::all());

        $this->crearUsuariosDePrueba();
    }

    private function crearPermisos(): void
    {
        foreach (self::MODELOS as $modelo) {
            foreach (self::ACCIONES as $accion) {
                Permission::firstOrCreate(
                    ['name' => "{$accion}:{$modelo}"],
                    ['guard_name' => 'web'],
                );
            }
        }

        foreach (self::PAGINAS as $pagina) {
            Permission::firstOrCreate(
                ['name' => "View:{$pagina}"],
                ['guard_name' => 'web'],
            );
        }

        foreach ([...self::PERMISOS_EXTRA, ...self::PERMISOS_PROPIOS, ...self::PERMISOS_CAJA, ...self::PERMISOS_SALON] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso], ['guard_name' => 'web']);
        }
    }

    /**
     * @param array<int, string> $permisos
     */
    private function rol(string $nombre, array $permisos): Role
    {
        $rol = Role::firstOrCreate(['name' => $nombre], ['guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return $rol;
    }

    /**
     * CRUD estándar de un Resource administrable.
     *
     * @return array<int, string>
     */
    private function crud(string $modelo): array
    {
        return [
            "ViewAny:{$modelo}", "View:{$modelo}", "Create:{$modelo}",
            "Update:{$modelo}", "Delete:{$modelo}",
        ];
    }

    /**
     * Solo lectura de un Resource.
     *
     * @return array<int, string>
     */
    private function lectura(string $modelo): array
    {
        return ["ViewAny:{$modelo}", "View:{$modelo}"];
    }

    private function crearUsuariosDePrueba(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Producción: se omiten los usuarios de prueba del restaurante.');

            return;
        }

        $usuarios = [
            ['administrador', 'Administrador', 'administrador@gmail.com'],
            ['gerente', 'Gerente', 'gerente@gmail.com'],
            ['cajero', 'Cajero', 'cajero@gmail.com'],
            ['mesero', 'Mesero', 'mesero@gmail.com'],
            ['contador', 'Contador', 'contador@gmail.com'],
        ];

        foreach ($usuarios as [$rol, $nombre, $email]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $nombre,
                    'password'          => Hash::make(self::PASSWORD_DEV),
                    'is_active'         => true,
                    'email_verified_at' => now(),
                ],
            );

            // Además del rol de dominio, necesita panel_user para que
            // canAccessPanel() lo deje entrar (si no, Filament rebota el login).
            $user->syncRoles([$rol, Utils::getPanelUserRoleName()]);
            $this->command?->info("✓ {$rol}: {$email} / ".self::PASSWORD_DEV);
        }
    }
}
