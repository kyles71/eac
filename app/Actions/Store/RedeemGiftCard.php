<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\CreditTransactionType;
use App\Models\GiftCard;
use App\Models\RestrictedCredit;
use App\Models\User;
use InvalidArgumentException;

final readonly class RedeemGiftCard
{
    /**
     * Redeem a gift card code and add the balance to the user's store credit
     * (or restricted credit if the gift card type has restrictions).
     */
    public function handle(string $code, User $user): GiftCard
    {
        $giftCard = GiftCard::query()->where('code', $code)->first();

        if ($giftCard === null) {
            throw new InvalidArgumentException('Gift card not found.');
        }

        if (! $giftCard->is_active) {
            throw new InvalidArgumentException('This gift card has been deactivated.');
        }

        if ($giftCard->isRedeemed()) {
            throw new InvalidArgumentException('This gift card has already been redeemed.');
        }

        if ($giftCard->remaining_amount <= 0) {
            throw new InvalidArgumentException('This gift card has no remaining balance.');
        }

        $amount = $giftCard->remaining_amount;

        $giftCard->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_at' => now(),
            'remaining_amount' => 0,
        ]);

        $giftCardType = $giftCard->giftCardType;

        if ($giftCardType !== null && $giftCardType->hasRestrictions()) {
            // Create a restricted credit entry instead of adding to general balance
            RestrictedCredit::query()->create([
                'user_id' => $user->id,
                'gift_card_type_id' => $giftCardType->id,
                'gift_card_id' => $giftCard->id,
                'balance' => $amount,
            ]);

            $user->adjustCredit(
                0,
                CreditTransactionType::GiftCardRedemption,
                $giftCard,
                "Redeemed restricted gift card {$giftCard->code} ({$giftCardType->restrictionSummary()})",
            );
        } else {
            $user->adjustCredit(
                $amount,
                CreditTransactionType::GiftCardRedemption,
                $giftCard,
                "Redeemed gift card {$giftCard->code}",
            );
        }

        return $giftCard->refresh();
    }
}
