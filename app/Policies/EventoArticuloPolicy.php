<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EventoArticulo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Al estilo de Filament Shield: cada método delega en el permiso
 * {Accion}:EventoArticulo, editable desde la pantalla de Roles.
 */
class EventoArticuloPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EventoArticulo');
    }

    public function view(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('View:EventoArticulo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EventoArticulo');
    }

    public function update(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('Update:EventoArticulo');
    }

    public function delete(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('Delete:EventoArticulo');
    }

    public function restore(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('Restore:EventoArticulo');
    }

    public function forceDelete(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('ForceDelete:EventoArticulo');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EventoArticulo');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EventoArticulo');
    }

    public function replicate(AuthUser $authUser, EventoArticulo $eventoArticulo): bool
    {
        return $authUser->can('Replicate:EventoArticulo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EventoArticulo');
    }
}
