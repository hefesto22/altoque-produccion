<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaPrepago;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:CuentaPrepago, editable desde la pantalla de Roles.
 */
class CuentaPrepagoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CuentaPrepago');
    }

    public function view(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('View:CuentaPrepago');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CuentaPrepago');
    }

    public function update(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('Update:CuentaPrepago');
    }

    public function delete(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('Delete:CuentaPrepago');
    }

    public function restore(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('Restore:CuentaPrepago');
    }

    public function forceDelete(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('ForceDelete:CuentaPrepago');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CuentaPrepago');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CuentaPrepago');
    }

    public function replicate(AuthUser $authUser, CuentaPrepago $cuentaPrepago): bool
    {
        return $authUser->can('Replicate:CuentaPrepago');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CuentaPrepago');
    }
}
