<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CourseHold;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CourseHoldPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CourseHold');
    }

    public function view(AuthUser $authUser, CourseHold $courseHold): bool
    {
        return $authUser->can('View:CourseHold');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CourseHold');
    }

    public function update(AuthUser $authUser, CourseHold $courseHold): bool
    {
        return $authUser->can('Update:CourseHold');
    }

    public function delete(AuthUser $authUser, CourseHold $courseHold): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
