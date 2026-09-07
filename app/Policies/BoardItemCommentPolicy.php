<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BoardItemComment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardItemCommentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:BoardItem') || $user->boardMemberships()->exists();
    }

    public function view(User $user, BoardItemComment $comment): bool
    {
        return $user->can('view', $comment->item);
    }

    public function create(User $user): bool
    {
        return $user->can('Update:BoardItem') || $user->boardMemberships()->exists();
    }

    public function update(User $user, BoardItemComment $comment): bool
    {
        return ! $comment->item->isArchived()
            && ! $comment->item->board->isArchived()
            && ($comment->author_id === $user->id || $comment->item->board->membershipRoleFor($user)?->canManage() === true);
    }

    public function delete(User $user, BoardItemComment $comment): bool
    {
        return ! $comment->item->isArchived()
            && ! $comment->item->board->isArchived()
            && ($user->can('Update:BoardItem') || $comment->item->board->membershipRoleFor($user)?->canManage() === true);
    }
}
