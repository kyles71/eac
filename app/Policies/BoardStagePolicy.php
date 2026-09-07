<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BoardStage;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardStagePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Board') || $user->boardMemberships()->exists();
    }

    public function view(User $user, BoardStage $stage): bool
    {
        return $user->can('view', $stage->board);
    }

    public function create(User $user): bool
    {
        return $user->can('Update:Board');
    }

    public function update(User $user, BoardStage $stage): bool
    {
        return $user->can('update', $stage->board);
    }

    public function delete(User $user, BoardStage $stage): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->can('Update:Board');
    }
}
