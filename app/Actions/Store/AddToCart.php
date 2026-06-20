<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddToCart
{
    public function handle(User $user, Product $product, int $quantity = 1): CartItem
    {
        return DB::transaction(function () use ($user, $product, $quantity): CartItem {
            $availability = app(ProductAvailabilityService::class)->resultFor($product, $user);

            if (! $availability->isPurchasable()) {
                throw new InvalidArgumentException($availability->message());
            }

            $cartItem = CartItem::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->first();

            if ($product->productable instanceof HasCapacity) {
                $availableCapacity = $product->productable->getAvailableCapacity();
                $existingQuantity = $cartItem->quantity ?? 0;

                $totalRequested = $existingQuantity + $quantity;

                if ($totalRequested > $availableCapacity) {
                    throw new InvalidArgumentException(
                        "Only {$availableCapacity} spot(s) remaining for this course."
                    );
                }
            }

            if ($cartItem !== null) {
                $cartItem->update([
                    'quantity' => $cartItem->quantity + $quantity,
                    'reminder_sent_at' => null,
                ]);

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
