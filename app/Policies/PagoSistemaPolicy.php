<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PagoSistema;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién ve y quién marca los pagos del sistema.
 *
 * El reparto es a propósito desparejo: el gerente (que es quien paga) solo
 * MIRA — cuánto lleva, cuánto falta, qué mes viene. Marcar una cuota como
 * pagada es del super_admin, que es quien recibe el dinero; si el que paga
 * pudiera darse por pagado solo, el registro no serviría de nada.
 *
 * El super_admin no necesita los permisos asignados: Acceso::puede() y el
 * bypass de Shield lo dejan pasar. Por eso `Update` no se le da a NINGÚN rol
 * en el seeder — así nadie más puede marcar, ni por error de configuración.
 */
class PagoSistemaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PagoSistema');
    }

    public function view(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return $authUser->can('View:PagoSistema');
    }

    /** El plan lo genera el contrato, no una persona. */
    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return $authUser->can('Update:PagoSistema');
    }

    /** Un mes del contrato no se borra: se marca o se deja pendiente. */
    public function delete(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, PagoSistema $pagoSistema): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
