<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Calendar;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CalendarPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Calendar');
    }

    public function view(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('View:Calendar');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Calendar');
    }

    public function update(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('Update:Calendar');
    }

    public function delete(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('Delete:Calendar');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Calendar');
    }

    public function restore(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('Restore:Calendar');
    }

    public function forceDelete(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('ForceDelete:Calendar');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Calendar');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Calendar');
    }

    public function replicate(AuthUser $authUser, Calendar $calendar): bool
    {
        return $authUser->can('Replicate:Calendar');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Calendar');
    }
}
