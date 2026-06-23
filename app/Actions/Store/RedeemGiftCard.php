<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\CreditTransactionType;
use App\Models\GiftCard;
use App\Models\User;
use App\Services\CreditLedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RedeemGiftCard
{
    public function handle(string $code, User $user): GiftCard
    {
        return DB::transaction(function () use ($code, $user): GiftCard {
            $giftCard = GiftCard::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

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
            $giftCardType = $giftCard->giftCardType;
            $description = $giftCardType !== null && $giftCardType->hasRestrictions()
                ? "Redeemed gift card {$giftCard->code} ({$giftCardType->restrictionSummary()})"
                : "Redeemed gift card {$giftCard->code}";

            app(CreditLedgerService::class)->issue(
                recipient: $user,
                amount: $amount,
                description: $description,
                restrictedToProductType: $giftCardType?->restricted_to_product_type,
                productIds: $giftCardType?->products()->pluck('products.id')->all() ?? [],
                source: $giftCard,
                transactionType: CreditTransactionType::GiftCardRedemption,
            );

            $giftCard->update([
                'redeemed_by_user_id' => $user->id,
                'redeemed_at' => now(),
                'remaining_amount' => 0,
            ]);

            return $giftCard->refresh();
        });
    }
}
