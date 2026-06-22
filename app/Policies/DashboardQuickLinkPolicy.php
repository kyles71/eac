<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DashboardQuickLink;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class DashboardQuickLinkPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:DashboardQuickLink');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:DashboardQuickLink');
    }

    public function update(AuthUser $user, DashboardQuickLink $link): bool
    {
        return $user->can('Update:DashboardQuickLink');
    }

    public function delete(AuthUser $user, DashboardQuickLink $link): bool
    {
        return $user->can('Delete:DashboardQuickLink');
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $user->can('DeleteAny:DashboardQuickLink');
    }

    public function reorder(AuthUser $user): bool
    {
        return $user->can('Update:DashboardQuickLink');
    }
}
