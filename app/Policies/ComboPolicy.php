<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Combo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Combo, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class ComboPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Combo');
    }

    public function view(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('View:Combo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Combo');
    }

    public function update(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('Update:Combo');
    }

    public function delete(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('Delete:Combo');
    }

    public function restore(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('Restore:Combo');
    }

    public function forceDelete(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('ForceDelete:Combo');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Combo');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Combo');
    }

    public function replicate(AuthUser $authUser, Combo $combo): bool
    {
        return $authUser->can('Replicate:Combo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Combo');
    }
}
