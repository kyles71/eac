<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DiscountCode;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class DiscountCodePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DiscountCode');
    }

    public function view(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('View:DiscountCode');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DiscountCode');
    }

    public function update(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('Update:DiscountCode');
    }

    public function delete(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('Delete:DiscountCode');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DiscountCode');
    }

    public function restore(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('Restore:DiscountCode');
    }

    public function forceDelete(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('ForceDelete:DiscountCode');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DiscountCode');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DiscountCode');
    }

    public function replicate(AuthUser $authUser, DiscountCode $discountCode): bool
    {
        return $authUser->can('Replicate:DiscountCode');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DiscountCode');
    }
}
