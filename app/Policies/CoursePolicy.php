<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class CoursePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Course');
    }

    public function view(User $authUser, Course $course): bool
    {
        return $authUser->can('View:Course') && $this->canAccessPrivateCourse($authUser, $course);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Course');
    }

    public function update(User $authUser, Course $course): bool
    {
        return $authUser->can('Update:Course')
            && (! $course->is_private || $authUser->hasAnyRole(['owner', 'super_admin']));
    }

    public function delete(User $authUser, Course $course): bool
    {
        return $authUser->can('Delete:Course')
            && $course->recurringPrivateLesson()->doesntExist()
            && (! $course->is_private || $authUser->hasAnyRole(['owner', 'super_admin']))
            && ($course->product?->canBeDeleted() ?? true);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Course');
    }

    private function canAccessPrivateCourse(User $authUser, Course $course): bool
    {
        return ! $course->is_private
            || $authUser->hasAnyRole(['owner', 'super_admin'])
            || $course->teachers()->whereKey($authUser->id)->exists();
    }
}
