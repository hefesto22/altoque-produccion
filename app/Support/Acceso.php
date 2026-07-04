<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Helper de acceso del panel. Toda decisión de acceso pasa por un permiso
 * de Shield editable en la pantalla de Roles — nunca por listas de roles
 * hardcodeadas (tieneAlguno() se eliminó en la migración a Shield).
 */
final class Acceso
{
    /**
     * ¿El usuario actual tiene este permiso (spatie/Shield)? El super_admin
     * siempre pasa (Shield está con define_via_gate=false, así que el
     * bypass se resuelve aquí).
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
