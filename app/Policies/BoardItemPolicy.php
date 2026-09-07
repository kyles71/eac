<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BoardInteractionMode;
use App\Models\BoardItem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:BoardItem') || $user->boardMemberships()->exists();
    }

    public function view(User $user, BoardItem $boardItem): bool
    {
        return $user->can('View:BoardItem') || $boardItem->board->membershipRoleFor($user) !== null;
    }

    public function update(User $user, BoardItem $boardItem): bool
    {
        if ($boardItem->board->isArchived() || $boardItem->isArchived()) {
            return false;
        }

        if ($user->can('Update:BoardItem')) {
            return true;
        }

        $role = $boardItem->board->membershipRoleFor($user);

        return $role?->canManage() === true
            || ($boardItem->board->interaction_mode === BoardInteractionMode::Collaborative && $role?->canContribute() === true)
            || ($role?->canContribute() === true && $boardItem->created_by_user_id === $user->id);
    }

    public function move(User $user, BoardItem $boardItem): bool
    {
        return $this->canManageWorkflow($user, $boardItem);
    }

    public function assign(User $user, BoardItem $boardItem): bool
    {
        return $this->canManageWorkflow($user, $boardItem);
    }

    public function archive(User $user, BoardItem $boardItem): bool
    {
        return ! $boardItem->board->isArchived()
            && ($user->can('Update:BoardItem') || $boardItem->board->membershipRoleFor($user)?->canManage() === true);
    }

    public function comment(User $user, BoardItem $boardItem): bool
    {
        return ! $boardItem->board->isArchived()
            && ! $boardItem->isArchived()
            && ($user->can('Update:BoardItem')
                || $boardItem->board->membershipRoleFor($user)?->canContribute() === true);
    }

    private function canManageWorkflow(User $user, BoardItem $boardItem): bool
    {
        if ($boardItem->board->isArchived() || $boardItem->isArchived()) {
            return false;
        }

        $role = $boardItem->board->membershipRoleFor($user);

        return $user->can('Update:BoardItem')
            || $role?->canManage() === true
            || ($boardItem->board->interaction_mode === BoardInteractionMode::Collaborative && $role?->canContribute() === true);
    }
}
