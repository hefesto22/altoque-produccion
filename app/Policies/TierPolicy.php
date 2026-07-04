<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Tier, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class TierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Tier');
    }

    public function view(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('View:Tier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Tier');
    }

    public function update(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('Update:Tier');
    }

    public function delete(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('Delete:Tier');
    }

    public function restore(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('Restore:Tier');
    }

    public function forceDelete(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('ForceDelete:Tier');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Tier');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Tier');
    }

    public function replicate(AuthUser $authUser, Tier $tier): bool
    {
        return $authUser->can('Replicate:Tier');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Tier');
    }
}
