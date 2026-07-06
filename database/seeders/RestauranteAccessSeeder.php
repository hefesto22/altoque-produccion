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
        'CorteCaja', 'PedidoOnline', 'PeriodoFiscal', 'Producto', 'Tier',
        'User', 'Venta',
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
         * contador: solo lectura fiscal + export.
         */

        $this->rol('administrador', [
            ...$this->crud('Producto'),
            ...$this->crud('Tier'),
            ...$this->crud('Combo'),
            ...$this->crud('ComboEspecial'),
            ...$this->crud('Cliente'),
            ...$this->crud('Compra'),
            ...$this->lectura('Venta'), // las ventas nacen del POS y no se editan
            'ViewAny:Cai', 'View:Cai', 'Create:Cai', 'Update:Cai', // sin Delete: un rango CAI no se borra
            'ViewAny:CorteCaja', 'View:CorteCaja', 'Update:CorteCaja', // Update = acción "corregir"
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            'ViewAny:PeriodoFiscal', 'View:PeriodoFiscal', 'Create:PeriodoFiscal', 'Update:PeriodoFiscal',
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina', 'View:MenuDelDia',
            'View:DeclaracionIsvMensual', 'View:LibrosFiscales', 'View:DatosEmpresaPage',
            ...self::PERMISOS_EXTRA,
        ]);

        $this->rol('gerente', [
            ...$this->crud('Producto'),
            ...$this->crud('Tier'),
            ...$this->crud('Combo'),
            ...$this->crud('ComboEspecial'),
            ...$this->crud('Cliente'),
            ...$this->crud('Compra'),
            ...$this->lectura('Venta'),
            ...$this->lectura('Cai'),
            ...$this->lectura('CorteCaja'), // sin Update: corregir cortes es del administrador
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            ...$this->lectura('PeriodoFiscal'),
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina', 'View:MenuDelDia',
            'View:DeclaracionIsvMensual', 'View:LibrosFiscales',
            ...self::PERMISOS_EXTRA, // incluye AnularFactura (decisión 2026-07-03)
        ]);

        $this->rol('cajero', [
            ...$this->lectura('Venta'),
            ...$this->lectura('CorteCaja'), // ve su corte; VerCortesTodos amplía a todos
            'ViewAny:PedidoOnline', 'View:PedidoOnline', 'Update:PedidoOnline',
            'View:PuntoDeVenta', 'View:BandejaPedidos', 'View:Cocina',
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

        foreach (self::PERMISOS_EXTRA as $permiso) {
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
