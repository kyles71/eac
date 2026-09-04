<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BoardItemActivity;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BoardItemActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:BoardItem') || $user->boardMemberships()->exists();
    }

    public function view(User $user, BoardItemActivity $activity): bool
    {
        return $user->can('view', $activity->item);
    }
}
