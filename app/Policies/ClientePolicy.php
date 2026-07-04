<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Cliente, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class ClientePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cliente');
    }

    public function view(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('View:Cliente');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cliente');
    }

    public function update(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('Update:Cliente');
    }

    public function delete(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('Delete:Cliente');
    }

    public function restore(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('Restore:Cliente');
    }

    public function forceDelete(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('ForceDelete:Cliente');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Cliente');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Cliente');
    }

    public function replicate(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('Replicate:Cliente');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Cliente');
    }
}
