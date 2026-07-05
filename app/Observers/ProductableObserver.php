<?php

declare(strict_types=1);

namespace App\Observers;

use App\Contracts\Productable;
use App\Models\Product;

final class ProductableObserver
{
    public function deleting(Productable $productable): bool
    {
        $product = $productable->product()->first();

        if (! $product instanceof Product) {
            return true;
        }

        return $product->canBeDeleted() && (bool) $product->delete();
    }
}
