<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class StudentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Student');
    }

    public function view(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('View:Student');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Student');
    }

    public function update(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('Update:Student');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Student');
    }
}
