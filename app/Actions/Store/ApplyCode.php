<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\DiscountCode;
use App\Models\GiftCard;
use App\Models\User;
use InvalidArgumentException;

final readonly class ApplyCode
{
    /**
     * Try to apply a code as a discount code or redeem it as a gift card.
     * Discount codes take priority over gift cards.
     *
     * @param  array<int, int>  $productIds
     * @return array{type: 'discount', discountCode: DiscountCode}|array{type: 'gift_card', giftCard: GiftCard}
     */
    public function handle(string $code, User $user, int $subtotal, array $productIds = []): array
    {
        $code = mb_trim($code);

        if ($code === '') {
            throw new InvalidArgumentException('Please enter a code.');
        }

        // Try as a discount code first
        $discountCode = DiscountCode::query()->where('code', $code)->first();

        if ($discountCode !== null) {
            $applyDiscount = new ApplyDiscountCode;
            $validatedCode = $applyDiscount->handle($code, $user, $subtotal, $productIds);

            return ['type' => 'discount', 'discountCode' => $validatedCode];
        }

        // Try as a gift card
        $giftCard = GiftCard::query()->where('code', $code)->first();

        if ($giftCard !== null) {
            $redeemGiftCard = new RedeemGiftCard;
            $redeemedCard = $redeemGiftCard->handle($code, $user);

            return ['type' => 'gift_card', 'giftCard' => $redeemedCard];
        }

        throw new InvalidArgumentException('Invalid code. Please check and try again.');
    }
}
