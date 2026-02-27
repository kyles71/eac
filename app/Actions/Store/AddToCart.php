<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\CartItem;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddToCart
{
    public function handle(User $user, Product $product, int $quantity = 1): CartItem
    {
        return DB::transaction(function () use ($user, $product, $quantity): CartItem {
            if (! $product->is_active) {
                throw new InvalidArgumentException('This product is not available for purchase.');
            }

            if ($product->price <= 0) {
                throw new InvalidArgumentException('This product does not have a valid price.');
            }

            if ($product->productable instanceof Course) {
                $availableCapacity = $product->productable->availableCapacity();
                $existingQuantity = CartItem::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->value('quantity') ?? 0;

                $totalRequested = $existingQuantity + $quantity;

                if ($totalRequested > $availableCapacity) {
                    throw new InvalidArgumentException(
                        "Only {$availableCapacity} spot(s) remaining for this course."
                    );
                }
            }

            $cartItem = CartItem::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->first();

            if ($cartItem !== null) {
                $cartItem->increment('quantity', $quantity);

                return $cartItem->refresh();
            }

            return CartItem::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        });
    }
}
