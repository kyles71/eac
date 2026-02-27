<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\CartItem;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateCartQuantity
{
    public function handle(User $user, int $cartItemId, int $quantity): CartItem
    {
        return DB::transaction(function () use ($user, $cartItemId, $quantity): CartItem {
            $cartItem = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', $user->id)
                ->with('product.productable')
                ->first();

            if ($cartItem === null) {
                throw new InvalidArgumentException('Cart item not found.');
            }

            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            /** @var \App\Models\Product $product */
            $product = $cartItem->product;

            if ($product->productable instanceof Course) {
                $availableCapacity = $product->productable->availableCapacity();

                if ($quantity > $availableCapacity) {
                    throw new InvalidArgumentException(
                        "Only {$availableCapacity} spot(s) remaining for this course."
                    );
                }
            }

            $cartItem->update(['quantity' => $quantity]);

            return $cartItem->refresh();
        });
    }
}
