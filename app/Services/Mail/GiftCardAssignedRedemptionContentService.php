<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Filament\User\Pages\Billing;
use App\Models\GiftCard;
use App\Models\User;
use LogicException;

final readonly class GiftCardAssignedRedemptionContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(GiftCard $giftCard, User $recipient): array
    {
        $giftCard->loadMissing(['giftCardType', 'purchasedBy']);

        $purchaser = $giftCard->purchasedBy;

        if (! $purchaser instanceof User) {
            throw new LogicException('The gift card purchaser is required.');
        }

        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'recipient.first_name' => $recipient->first_name,
                'recipient.full_name' => self::fullName($recipient),
                'recipient.email' => $recipient->email,
                'purchaser.first_name' => $purchaser->first_name,
                'purchaser.full_name' => self::fullName($purchaser),
                'purchaser.email' => $purchaser->email,
                'gift_card.code' => $giftCard->code,
                'gift_card.value' => $giftCard->formattedInitialAmount(),
                'gift_card.restrictions' => $giftCard->giftCardType?->restrictionSummary() ?? 'Unrestricted',
                'gift_card.redemption_date' => ($giftCard->redeemed_at ?? now())
                    ->timezone($displayTimezone)
                    ->format('F j, Y'),
            ],
            'slots' => [
                'billing-action' => view('mail.gift-card-redeem-action', [
                    'redeemUrl' => Billing::getUrl(['tab' => 'credits'], panel: 'user'),
                    'label' => 'View Store Credit',
                ])->render(),
            ],
        ];
    }

    private static function fullName(User $user): string
    {
        return mb_trim("{$user->first_name} {$user->last_name}");
    }
}
