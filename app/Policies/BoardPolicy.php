<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BoardInteractionMode;
use App\Models\Board;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Board')
            || $user->can('Create:Board')
            || $user->boardMemberships()->exists();
    }

    public function view(User $user, Board $board): bool
    {
        return $user->can('View:Board') || $board->membershipRoleFor($user) !== null;
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Board');
    }

    public function update(User $user, Board $board): bool
    {
        return ! $board->isArchived()
            && ($user->can('Update:Board') || $board->membershipRoleFor($user)?->canManage() === true);
    }

    public function delete(User $user, Board $board): bool
    {
        return $user->can('Delete:Board');
    }

    public function createItem(User $user, Board $board): bool
    {
        return ! $board->isArchived() && ($user->can('Update:BoardItem')
            || $board->membershipRoleFor($user)?->canContribute() === true);
    }

    public function manageWorkflow(User $user, Board $board): bool
    {
        $role = $board->membershipRoleFor($user);

        return ! $board->isArchived() && ($user->can('Update:BoardItem')
            || $role?->canManage() === true
            || ($board->interaction_mode === BoardInteractionMode::Collaborative && $role?->canContribute() === true));
    }

    public function comment(User $user, Board $board): bool
    {
        return ! $board->isArchived() && ($user->can('Update:BoardItem')
            || $board->membershipRoleFor($user)?->canContribute() === true);
    }

    public function manageMembers(User $user, Board $board): bool
    {
        return ! $board->isArchived()
            && $this->view($user, $board)
            && ($user->can('ManageMembers:Board') || $board->membershipRoleFor($user)?->canManage() === true);
    }

    public function archive(User $user, Board $board): bool
    {
        return ! $board->isArchived()
            && ($user->can('Delete:Board') || $board->membershipRoleFor($user)?->canManage() === true);
    }

    public function restore(User $user, Board $board): bool
    {
        return $board->isArchived()
            && ($user->can('Delete:Board') || $board->membershipRoleFor($user)?->canManage() === true);
    }
}
