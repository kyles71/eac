<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\AccessManagerService;
use Illuminate\Auth\Access\HandlesAuthorization;

final class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(User $authUser, User $user): bool
    {
        return $authUser->can('View:User');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(User $authUser, User $user): bool
    {
        return $authUser->can('Update:User');
    }

    public function delete(User $authUser, User $user): bool
    {
        return $authUser->can('Delete:User')
            && app(AccessManagerService::class)->canManageUser($authUser, $user);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:User');
    }

    public function manageAccess(User $authUser, User $user): bool
    {
        return $authUser->can('Manage:UserAccess')
            && app(AccessManagerService::class)->canManageUser($authUser, $user);
    }
}
