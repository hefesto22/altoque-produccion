<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cai;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Cai, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class CaiPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cai');
    }

    public function view(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('View:Cai');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cai');
    }

    public function update(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('Update:Cai');
    }

    public function delete(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('Delete:Cai');
    }

    public function restore(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('Restore:Cai');
    }

    public function forceDelete(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('ForceDelete:Cai');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Cai');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Cai');
    }

    public function replicate(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('Replicate:Cai');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Cai');
    }
}
