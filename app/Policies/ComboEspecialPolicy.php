<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ComboEspecial;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:ComboEspecial, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class ComboEspecialPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ComboEspecial');
    }

    public function view(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('View:ComboEspecial');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ComboEspecial');
    }

    public function update(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('Update:ComboEspecial');
    }

    public function delete(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('Delete:ComboEspecial');
    }

    public function restore(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('Restore:ComboEspecial');
    }

    public function forceDelete(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('ForceDelete:ComboEspecial');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ComboEspecial');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ComboEspecial');
    }

    public function replicate(AuthUser $authUser, ComboEspecial $comboEspecial): bool
    {
        return $authUser->can('Replicate:ComboEspecial');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ComboEspecial');
    }
}
