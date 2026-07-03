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
 * Roles, permisos y usuarios del restaurante.
 *
 * El super_admin lo maneja AdminUserSeeder (admin@gmail.com). Este
 * seeder agrega los roles operativos — administrador, gerente, cajero,
 * contador — con sus permisos y un usuario de prueba por rol.
 *
 * Los permisos siguen la convención de Filament Shield ({accion}_{recurso})
 * para que coincidan con los que generará `shield:generate` cuando se
 * construyan los Resources (Producto, Venta, Cai, etc.). Crearlos aquí
 * de forma idempotente deja el acceso listo desde el primer seed.
 *
 * Las fronteras por rol son DECISIÓN DE NEGOCIO (confirmadas con
 * Mauricio): el cajero no edita productos ni anula; el contador solo
 * lee y exporta; solo administrador gestiona CAI y anula facturas.
 *
 * Solo crea usuarios de prueba fuera de producción (contraseña débil).
 */
class RestauranteAccessSeeder extends Seeder
{
    private const PASSWORD_DEV = '12345678';

    /**
     * Permisos por recurso del dominio. Se crean todos; cada rol recibe
     * el subconjunto que le corresponde.
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISOS = [
        'producto'   => ['view_any', 'view', 'create', 'update', 'delete'],
        'venta'      => ['view_any', 'view', 'create'],
        'factura'    => ['view_any', 'view', 'create'],
        'cai'        => ['view_any', 'view', 'create', 'update'],
        'corte_caja' => ['view_any', 'view', 'create'],
    ];

    /** Permisos sueltos (páginas / acciones especiales). */
    /*
     * En PascalCase porque la pestaña "Permisos personalizados" de Shield
     * formatea los nombres con permissions.case = pascal: si no coinciden,
     * las casillas nunca reflejan lo asignado ni guardan sobre el permiso real.
     */
    private const PERMISOS_EXTRA = [
        'page_PuntoDeVenta',  // acceso a la pantalla de cobro (convención de páginas)
        'ExportVentas',       // descargar reporte del contador
        'VerCortesTodos',     // ver cortes de otros cajeros (supervisión)
        'AbrirTurno',         // abrir turno de caja (quien entrega el fondo)
        'AnularFactura',      // anular factura SAR (queda registrada, no se borra)
    ];

    /** Nombres viejos (snake) reemplazados por los PascalCase de arriba. */
    private const PERMISOS_OBSOLETOS = [
        'anular_factura', 'export_ventas', 'view_cortes_todos', 'abrir_turno',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Limpia los nombres snake reemplazados (idempotente).
        Permission::query()->whereIn('name', self::PERMISOS_OBSOLETOS)->delete();

        $this->crearPermisos();

        $this->rol('administrador', $this->todasLasPermisos());

        $this->rol('gerente', [
            ...$this->paraRecurso('producto'),
            ...$this->soloLectura('venta'),
            ...$this->soloLectura('factura'),
            ...$this->soloLectura('cai'),
            ...$this->soloLectura('corte_caja'),
            'ExportVentas',
            'VerCortesTodos',
            'AbrirTurno',
            'AnularFactura', // decisión de Mauricio (2026-07-03): el gerente también anula
        ]);

        $this->rol('cajero', [
            'view_any_venta', 'view_venta', 'create_venta',
            'view_factura', 'create_factura',
            'view_corte_caja', 'create_corte_caja',
            'page_PuntoDeVenta',
        ]);

        $this->rol('contador', [
            'view_any_venta', 'view_venta',
            'view_any_factura', 'view_factura',
            'ExportVentas',
        ]);

        $this->crearUsuariosDePrueba();
    }

    private function crearPermisos(): void
    {
        foreach (self::PERMISOS as $recurso => $acciones) {
            foreach ($acciones as $accion) {
                Permission::firstOrCreate(
                    ['name' => "{$accion}_{$recurso}"],
                    ['guard_name' => 'web'],
                );
            }
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

    /** @return array<int, string> */
    private function paraRecurso(string $recurso): array
    {
        return array_map(
            static fn (string $accion): string => "{$accion}_{$recurso}",
            self::PERMISOS[$recurso],
        );
    }

    /** @return array<int, string> */
    private function soloLectura(string $recurso): array
    {
        return ["view_any_{$recurso}", "view_{$recurso}"];
    }

    /** @return array<int, string> */
    private function todasLasPermisos(): array
    {
        $todas = [];

        foreach (array_keys(self::PERMISOS) as $recurso) {
            $todas = [...$todas, ...$this->paraRecurso($recurso)];
        }

        return [...$todas, ...self::PERMISOS_EXTRA];
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
