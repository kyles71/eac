<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class FulfillGiftCard
{
    /**
     * Create gift card(s) for a purchased GiftCardType order item.
     *
     * @return list<GiftCard>
     */
    public function handle(OrderItem $orderItem, User $purchaser): array
    {
        /** @var \App\Models\Product $product */
        $product = $orderItem->product;

        /** @var GiftCardType $giftCardType */
        $giftCardType = $product->productable;

        // Use the denomination if set, otherwise use the product price as the gift card value
        $amount = $giftCardType->denomination > 0
            ? $giftCardType->denomination
            : $product->price;

        $giftCards = [];

        for ($i = 0; $i < $orderItem->quantity; $i++) {
            /** @var GiftCard $giftCard */
            $giftCard = GiftCard::query()->create([
                'code' => $this->generateUniqueCode(),
                'initial_amount' => $amount,
                'remaining_amount' => $amount,
                'purchased_by_user_id' => $purchaser->id,
                'order_id' => $orderItem->order_id,
                'is_active' => true,
            ]);

            $giftCards[] = $giftCard;
        }

        return $giftCards;
    }

    /**
     * Generate a unique gift card code.
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = mb_strtoupper(Str::random(16));
        } while (GiftCard::query()->where('code', $code)->exists());

        return $code;
    }
}
