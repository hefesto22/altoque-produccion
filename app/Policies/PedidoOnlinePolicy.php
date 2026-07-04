<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PedidoOnline;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:PedidoOnline, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class PedidoOnlinePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PedidoOnline');
    }

    public function view(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('View:PedidoOnline');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PedidoOnline');
    }

    public function update(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('Update:PedidoOnline');
    }

    public function delete(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('Delete:PedidoOnline');
    }

    public function restore(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('Restore:PedidoOnline');
    }

    public function forceDelete(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('ForceDelete:PedidoOnline');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PedidoOnline');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PedidoOnline');
    }

    public function replicate(AuthUser $authUser, PedidoOnline $pedidoOnline): bool
    {
        return $authUser->can('Replicate:PedidoOnline');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PedidoOnline');
    }
}
