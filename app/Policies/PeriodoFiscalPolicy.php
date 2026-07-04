<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PeriodoFiscal;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:PeriodoFiscal, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class PeriodoFiscalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PeriodoFiscal');
    }

    public function view(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('View:PeriodoFiscal');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PeriodoFiscal');
    }

    public function update(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('Update:PeriodoFiscal');
    }

    public function delete(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('Delete:PeriodoFiscal');
    }

    public function restore(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('Restore:PeriodoFiscal');
    }

    public function forceDelete(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('ForceDelete:PeriodoFiscal');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PeriodoFiscal');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PeriodoFiscal');
    }

    public function replicate(AuthUser $authUser, PeriodoFiscal $periodoFiscal): bool
    {
        return $authUser->can('Replicate:PeriodoFiscal');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PeriodoFiscal');
    }
}
