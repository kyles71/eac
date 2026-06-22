<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class ProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Product');
    }

    public function view(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('View:Product');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Product');
    }

    public function update(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Update:Product');
    }

    public function delete(AuthUser $authUser, Product $product): bool
    {
        return $authUser->can('Delete:Product')
            && $product->canBeDeleted();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Product');
    }
}
