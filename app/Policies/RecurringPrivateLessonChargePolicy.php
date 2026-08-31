<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;

final class RecurringPrivateLessonChargePolicy
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
    public function view(User $user, RecurringPrivateLessonCharge $recurringPrivateLessonCharge): bool
    {
        $recurringPrivateLessonCharge->loadMissing('recurringPrivateLesson.course');

        return $user->hasAnyRole(['owner', 'super_admin'])
            || $recurringPrivateLessonCharge->recurringPrivateLesson->course
                ->teachers()
                ->whereKey($user->id)
                ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RecurringPrivateLessonCharge $recurringPrivateLessonCharge): bool
    {
        return $user->hasAnyRole(['owner', 'super_admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RecurringPrivateLessonCharge $recurringPrivateLessonCharge): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RecurringPrivateLessonCharge $recurringPrivateLessonCharge): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RecurringPrivateLessonCharge $recurringPrivateLessonCharge): bool
    {
        return false;
    }
}
