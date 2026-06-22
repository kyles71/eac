<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class EnrollmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Enrollment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Enrollment');
    }

    public function update(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Update:Enrollment');
    }

    public function delete(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Delete:Enrollment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Enrollment');
    }
}
