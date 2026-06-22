<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DashboardMessage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class DashboardMessagePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:DashboardMessage');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:DashboardMessage');
    }

    public function update(AuthUser $user, DashboardMessage $message): bool
    {
        return $user->can('Update:DashboardMessage');
    }

    public function delete(AuthUser $user, DashboardMessage $message): bool
    {
        return $user->can('Delete:DashboardMessage');
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $user->can('DeleteAny:DashboardMessage');
    }
}
