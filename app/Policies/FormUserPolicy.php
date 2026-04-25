<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class FormUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FormUser');
    }

    public function view(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('View:FormUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FormUser');
    }

    public function update(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('Update:FormUser');
    }

    public function delete(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('Delete:FormUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FormUser');
    }

    public function restore(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('Restore:FormUser');
    }

    public function forceDelete(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('ForceDelete:FormUser');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FormUser');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FormUser');
    }

    public function replicate(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('Replicate:FormUser');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FormUser');
    }
}
