<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Holiday;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class HolidayPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Holiday');
    }

    public function view(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('View:Holiday');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Holiday');
    }

    public function update(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('Update:Holiday');
    }

    public function delete(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('Delete:Holiday');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Holiday');
    }

    public function restore(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('Restore:Holiday');
    }

    public function forceDelete(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('ForceDelete:Holiday');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Holiday');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Holiday');
    }

    public function replicate(AuthUser $authUser, Holiday $holiday): bool
    {
        return $authUser->can('Replicate:Holiday');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Holiday');
    }
}
