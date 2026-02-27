<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\CreditTransactionType;
use App\Models\GiftCard;
use App\Models\User;
use InvalidArgumentException;

final readonly class RedeemGiftCard
{
    /**
     * Redeem a gift card code and add the balance to the user's store credit.
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

        $user->adjustCredit(
            $amount,
            CreditTransactionType::GiftCardRedemption,
            $giftCard,
            "Redeemed gift card {$giftCard->code}",
        );

        return $giftCard->refresh();
    }
}
