<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Helper de acceso por rol para las pantallas del panel. Centraliza la
 * regla "¿el usuario actual tiene alguno de estos roles?" — el
 * super_admin siempre pasa. Evita repartir `hasRole` por todos lados.
 */
final class Acceso
{
    /**
     * @param array<int, string> $roles
     */
    public static function tieneAlguno(array $roles): bool
    {
        $user = Auth::user();

        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasAnyRole($roles);
    }

    /**
     * ¿El usuario actual tiene este permiso (spatie/Shield)? El super_admin
     * siempre pasa (Shield está con define_via_gate=false, así que el
     * bypass se resuelve aquí, igual que en tieneAlguno()).
     *
     * Preferir esto sobre listas de roles hardcodeadas: qué rol puede qué
     * se decide en datos (pantalla de Roles), no en código.
     */
    public static function puede(string $permiso): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
            return true;
        }

        return $user->can($permiso);
    }
}
