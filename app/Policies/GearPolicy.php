<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gear;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class GearPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Gear');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Gear');
    }

    public function view(AuthUser $authUser, Gear $gear): bool
    {
        return $authUser->can('View:Gear');
    }

    public function update(AuthUser $authUser, Gear $gear): bool
    {
        return $authUser->can('Update:Gear');
    }

    public function delete(AuthUser $authUser, Gear $gear): bool
    {
        return $authUser->can('Delete:Gear')
            && $gear->canBeDeleted();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Gear');
    }
}
