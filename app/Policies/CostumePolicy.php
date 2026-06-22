<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Costume;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CostumePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Costume');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Costume');
    }

    public function update(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Update:Costume');
    }

    public function delete(AuthUser $authUser, Costume $costume): bool
    {
        return $authUser->can('Delete:Costume')
            && ($costume->product?->canBeDeleted() ?? true);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Costume');
    }
}
