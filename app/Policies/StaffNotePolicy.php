<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\StaffNote;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class StaffNotePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:StaffNote');
    }

    public function view(User $user, StaffNote $staffNote): bool
    {
        return $user->can('View:StaffNote')
            && $user->can('view', $staffNote->student);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:StaffNote');
    }

    public function update(User $user, StaffNote $staffNote): bool
    {
        return $user->can('Update:StaffNote')
            && $user->can('view', $staffNote->student)
            && $this->isAuthorOrUnrestrictedStaff($user, $staffNote);
    }

    public function delete(User $user, StaffNote $staffNote): bool
    {
        return $user->can('Delete:StaffNote')
            && $user->can('view', $staffNote->student)
            && $this->isAuthorOrUnrestrictedStaff($user, $staffNote);
    }

    private function isAuthorOrUnrestrictedStaff(User $user, StaffNote $staffNote): bool
    {
        return $staffNote->author_id === $user->id
            || $user->hasAnyRole([Role::SUPER_ADMIN, Role::OWNER]);
    }
}
