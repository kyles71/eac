<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Models\CartItem;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddToCart
{
    public function handle(User $user, Product $product, int $quantity = 1, ?int $customGiftCardAmount = null): CartItem
    {
        return DB::transaction(function () use ($user, $product, $quantity, $customGiftCardAmount): CartItem {
            $product->loadMissing('productable');
            $availability = app(ProductAvailabilityService::class)->resultFor($product, $user);

            if (! $availability->isPurchasable()) {
                throw new InvalidArgumentException($availability->message());
            }

            $customGiftCardAmount = $this->normalizeCustomGiftCardAmount($product, $customGiftCardAmount);

            $cartItem = CartItem::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('custom_gift_card_amount', $customGiftCardAmount)
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
                'custom_gift_card_amount' => $customGiftCardAmount,
            ]);
        });
    }

    private function normalizeCustomGiftCardAmount(Product $product, ?int $customGiftCardAmount): int
    {
        $amount = $customGiftCardAmount ?? 0;

        if (! $product->productable instanceof GiftCardType || ! $product->productable->allows_custom_amount) {
            if ($amount > 0) {
                throw new InvalidArgumentException('Custom gift card amounts are only available for enabled gift cards.');
            }

            return 0;
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Please enter a gift card amount.');
        }

        if ($amount % 100 !== 0) {
            throw new InvalidArgumentException('Gift card amounts must be whole dollars.');
        }

        $minimumAmount = $product->productable->minimumCustomAmount();

        if ($amount < $minimumAmount) {
            throw new InvalidArgumentException('Gift card amount must be at least '.format_money($minimumAmount).'.');
        }

        return $amount;
    }
}
