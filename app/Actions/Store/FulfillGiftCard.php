<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\GiftCards\GiftCardCodeGenerator;

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

        // Fixed gift cards use denomination so promotions can discount purchase price without lowering value.
        $amount = $orderItem->customGiftCardAmount() ?? $giftCardType->denomination;

        $giftCards = [];

        for ($i = 0; $i < $orderItem->quantity; $i++) {
            /** @var GiftCard $giftCard */
            $giftCard = GiftCard::query()->create([
                'code' => app(GiftCardCodeGenerator::class)->generate(),
                'gift_card_type_id' => $giftCardType->id,
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
}
