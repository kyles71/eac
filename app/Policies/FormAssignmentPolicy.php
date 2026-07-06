<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormAssignment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class FormAssignmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:FormUser');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FormAssignment $formAssignment): bool
    {
        return $this->isRespondent($user, $formAssignment) || $user->can('View:FormUser');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('Create:FormUser');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FormAssignment $formAssignment): bool
    {
        return $user->can('Update:FormUser')
            || (
                $this->isRespondent($user, $formAssignment)
                && $formAssignment->isActive()
                && (! $formAssignment->isCompleted() || $formAssignment->formCanBeUpdated())
            );
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FormAssignment $formAssignment): bool
    {
        return $user->can('Delete:FormUser');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:FormUser');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FormAssignment $formAssignment): bool
    {
        return $user->can('Restore:FormUser');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FormAssignment $formAssignment): bool
    {
        return $user->can('ForceDelete:FormUser');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:FormUser');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:FormUser');
    }

    private function isRespondent(User $user, FormAssignment $formAssignment): bool
    {
        return $formAssignment->respondent_type === $user->getMorphClass()
            && (int) $formAssignment->respondent_id === (int) $user->getKey();
    }
}
