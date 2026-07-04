<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Producto, editable desde la pantalla de Roles. El super_admin
 * pasa porque su rol tiene todos los permisos sincronizados en la base.
 */
class ProductoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Producto');
    }

    public function view(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('View:Producto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Producto');
    }

    public function update(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Update:Producto');
    }

    public function delete(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Delete:Producto');
    }

    public function restore(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Restore:Producto');
    }

    public function forceDelete(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('ForceDelete:Producto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Producto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Producto');
    }

    public function replicate(AuthUser $authUser, Producto $producto): bool
    {
        return $authUser->can('Replicate:Producto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Producto');
    }
}
