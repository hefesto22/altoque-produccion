<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CorteCaja;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:CorteCaja, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class CorteCajaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CorteCaja');
    }

    public function view(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('View:CorteCaja');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CorteCaja');
    }

    public function update(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('Update:CorteCaja');
    }

    public function delete(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('Delete:CorteCaja');
    }

    public function restore(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('Restore:CorteCaja');
    }

    public function forceDelete(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('ForceDelete:CorteCaja');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CorteCaja');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CorteCaja');
    }

    public function replicate(AuthUser $authUser, CorteCaja $corteCaja): bool
    {
        return $authUser->can('Replicate:CorteCaja');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CorteCaja');
    }
}
