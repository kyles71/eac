<?php

declare(strict_types=1);

namespace App\Observers;

use App\Contracts\Productable;

final class ProductableObserver
{
    public function deleting(Productable $productable): bool
    {
        $product = $productable->product()->first();

        if ($product === null) {
            return true;
        }

        return $product->canBeDeleted() && (bool) $product->delete();
    }
}
