<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\OrderStatus;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\User;
use App\Services\Mail\GiftCardDeliveryContentService;
use Illuminate\Support\Facades\DB;

final readonly class SendGiftCardDeliveryEmails
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private GiftCardDeliveryContentService $content,
    ) {}

    public function handle(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->with('user')
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null || $lockedOrder->status !== OrderStatus::Completed) {
                return 0;
            }

            $purchaser = $lockedOrder->user;

            if (! $purchaser instanceof User || ! filter_var($purchaser->email, FILTER_VALIDATE_EMAIL)) {
                return 0;
            }

            $giftCards = GiftCard::query()
                ->where('order_id', $lockedOrder->id)
                ->where('purchased_by_user_id', $purchaser->id)
                ->whereNull('delivery_email_queued_at')
                ->with(['giftCardType', 'order', 'purchasedBy'])
                ->lockForUpdate()
                ->get();

            $queuedCount = 0;

            /** @var GiftCard $giftCard */
            foreach ($giftCards as $giftCard) {
                $payload = $this->content->for($giftCard);
                $queued = $this->managedEmail->handle(
                    recipients: $purchaser->email,
                    emailTypeKey: 'gift-card-delivery',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                );

                if (! $queued) {
                    continue;
                }

                $giftCard->update(['delivery_email_queued_at' => now()]);
                $queuedCount++;
            }

            return $queuedCount;
        });
    }
}
