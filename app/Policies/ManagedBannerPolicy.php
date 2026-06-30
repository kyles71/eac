<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ManagedBanner;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class ManagedBannerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:ManagedBanner');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:ManagedBanner');
    }

    public function update(AuthUser $user, ManagedBanner $managedBanner): bool
    {
        return $user->can('Update:ManagedBanner');
    }

    public function delete(AuthUser $user, ManagedBanner $managedBanner): bool
    {
        return $user->can('Delete:ManagedBanner');
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $user->can('DeleteAny:ManagedBanner');
    }
}
