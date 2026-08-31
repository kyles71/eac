<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecurringPrivateLesson;
use App\Models\User;

final class RecurringPrivateLessonPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'super_admin', 'teacher']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RecurringPrivateLesson $recurringPrivateLesson): bool
    {
        return $this->managesRecurringPrivateLessons($user)
            || $recurringPrivateLesson->user_id === $user->id
            || $recurringPrivateLesson->course->teachers()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->managesRecurringPrivateLessons($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RecurringPrivateLesson $recurringPrivateLesson): bool
    {
        return $this->managesRecurringPrivateLessons($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RecurringPrivateLesson $recurringPrivateLesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RecurringPrivateLesson $recurringPrivateLesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RecurringPrivateLesson $recurringPrivateLesson): bool
    {
        return false;
    }

    private function managesRecurringPrivateLessons(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'super_admin']);
    }
}
