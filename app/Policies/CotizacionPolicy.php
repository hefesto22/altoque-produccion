<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cotizacion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:Cotizacion, editable desde la pantalla de Roles.
 */
class CotizacionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cotizacion');
    }

    public function view(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('View:Cotizacion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cotizacion');
    }

    public function update(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('Update:Cotizacion');
    }

    public function delete(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('Delete:Cotizacion');
    }

    public function restore(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('Restore:Cotizacion');
    }

    public function forceDelete(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('ForceDelete:Cotizacion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Cotizacion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Cotizacion');
    }

    public function replicate(AuthUser $authUser, Cotizacion $cotizacion): bool
    {
        return $authUser->can('Replicate:Cotizacion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Cotizacion');
    }
}
