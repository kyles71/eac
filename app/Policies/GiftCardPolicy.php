<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GiftCard;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class GiftCardPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GiftCard');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GiftCard');
    }

    public function redeem(AuthUser $authUser, GiftCard $giftCard): bool
    {
        return $giftCard->isRedeemable() && $authUser->can('Redeem:GiftCard');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GiftCard');
    }
}
