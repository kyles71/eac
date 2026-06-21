<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Filament\User\Pages\Billing;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\User;
use LogicException;

final readonly class GiftCardDeliveryContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(GiftCard $giftCard): array
    {
        $giftCard->loadMissing(['giftCardType', 'order', 'purchasedBy']);

        $purchaser = $giftCard->purchasedBy;
        $order = $giftCard->order;

        if (! $purchaser instanceof User || ! $order instanceof Order) {
            throw new LogicException('The gift card purchaser and order are required.');
        }

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'purchaser.first_name' => $purchaser->first_name,
                'purchaser.full_name' => mb_trim("{$purchaser->first_name} {$purchaser->last_name}"),
                'purchaser.email' => $purchaser->email,
                'gift_card.code' => $giftCard->code,
                'gift_card.value' => $giftCard->formattedInitialAmount(),
                'gift_card.restrictions' => $giftCard->giftCardType?->restrictionSummary() ?? 'Unrestricted',
                'order.number' => (string) $order->id,
                'order.date' => $order->created_at->format('F j, Y'),
            ],
            'slots' => [
                'redeem-action' => view('mail.gift-card-redeem-action', [
                    'redeemUrl' => Billing::getUrl(['tab' => 'credits'], panel: 'user'),
                ])->render(),
            ],
        ];
    }
}
