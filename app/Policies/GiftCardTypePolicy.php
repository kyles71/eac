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

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GiftCardType');
    }

    public function delete(AuthUser $authUser, GiftCardType $giftCardType): bool
    {
        return $authUser->can('Delete:GiftCardType')
            && ($giftCardType->product?->canBeDeleted() ?? true);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GiftCardType');
    }
}
