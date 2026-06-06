<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Costume;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CostumePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Costume');
    }

    public function view(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('View:Costume');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Costume');
    }

    public function update(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Update:Costume');
    }

    public function delete(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Delete:Costume')
            && ($costume->product?->canBeDeleted() ?? true);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Costume');
    }

    public function restore(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Restore:Costume');
    }

    public function forceDelete(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('ForceDelete:Costume');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Costume');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Costume');
    }

    public function replicate(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Replicate:Costume');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Costume');
    }
}
