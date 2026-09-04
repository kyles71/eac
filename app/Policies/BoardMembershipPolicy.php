<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BoardMembership;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardMembershipPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ManageMembers:Board')
            || $user->boardMemberships()->where('role', 'manager')->exists();
    }

    public function view(User $user, BoardMembership $membership): bool
    {
        return $user->can('manageMembers', $membership->board);
    }

    public function create(User $user): bool
    {
        return $user->can('ManageMembers:Board');
    }

    public function update(User $user, BoardMembership $membership): bool
    {
        return $this->view($user, $membership);
    }

    public function delete(User $user, BoardMembership $membership): bool
    {
        return $this->view($user, $membership);
    }
}
