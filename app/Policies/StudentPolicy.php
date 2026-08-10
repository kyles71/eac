<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class StudentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Student');
    }

    public function view(User $authUser, Student $student): bool
    {
        return $authUser->can('View:Student')
            && $student->isAccessibleToAdminUser($authUser);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Student');
    }

    public function update(User $authUser, Student $student): bool
    {
        return $authUser->can('Update:Student')
            && $student->isAccessibleToAdminUser($authUser);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Student');
    }
}
