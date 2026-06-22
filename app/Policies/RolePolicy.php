<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessManagerService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

final class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Role')
            && app(AccessManagerService::class)->highestRoleWeight($authUser) !== null;
    }

    public function view(User $authUser, Role $role): bool
    {
        return $authUser->can('View:Role')
            && app(AccessManagerService::class)->canManageRole($authUser, $role);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Role')
            && app(AccessManagerService::class)->highestRoleWeight($authUser) !== null;
    }

    public function update(User $authUser, Role $role): bool
    {
        return $authUser->can('Update:Role')
            && app(AccessManagerService::class)->canManageRole($authUser, $role);
    }

    public function delete(User $authUser, Role $role): Response
    {
        if (! $authUser->can('Delete:Role')) {
            return Response::deny('You do not have permission to delete roles.');
        }

        if (! app(AccessManagerService::class)->canManageRole($authUser, $role)) {
            return Response::deny('You may only delete roles below your own highest role.');
        }

        if ($role->users()->exists()) {
            return Response::deny('Reassign or remove every user from this role before deleting it.');
        }

        return Response::allow();
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Role')
            && app(AccessManagerService::class)->highestRoleWeight($authUser) !== null;
    }
}
