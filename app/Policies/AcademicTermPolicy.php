<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicTerm;
use App\Models\Role;
use App\Models\User;

final class AcademicTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, AcademicTerm $academicTerm): bool
    {
        return $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, AcademicTerm $academicTerm): bool
    {
        return $this->isOwner($user);
    }

    public function delete(User $user, AcademicTerm $academicTerm): bool
    {
        return $this->isOwner($user) && $academicTerm->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    private function isOwner(User $user): bool
    {
        return $user->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN]);
    }
}
