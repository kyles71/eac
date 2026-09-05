<?php

declare(strict_types=1);

namespace App\Observers;

use App\Contracts\Productable;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

final class ProductableObserver
{
    public function deleting(Model&Productable $productable): bool
    {
        $products = Product::query()
            ->forProductable($productable)
            ->get();

        if ($products->contains(fn (Product $product): bool => ! $product->canBeDeleted())) {
            return false;
        }

        foreach ($products as $product) {
            if (! $product->delete()) {
                return false;
            }
        }

        return true;
    }
}
