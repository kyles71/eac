<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class DiscountCodePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DiscountCode');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DiscountCode');
    }
}
