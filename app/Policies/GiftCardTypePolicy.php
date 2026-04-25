<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GiftCardType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class GiftCardTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GiftCardType');
    }

    public function view(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('View:GiftCardType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GiftCardType');
    }

    public function update(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('Update:GiftCardType');
    }

    public function delete(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('Delete:GiftCardType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GiftCardType');
    }

    public function restore(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('Restore:GiftCardType');
    }

    public function forceDelete(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('ForceDelete:GiftCardType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GiftCardType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GiftCardType');
    }

    public function replicate(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('Replicate:GiftCardType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GiftCardType');
    }
}
