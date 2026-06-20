<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Mail\ProductPurchaseNotificationContentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class SendProductPurchaseNotification
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private ProductPurchaseNotificationContentService $content,
    ) {}

    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null
                || $lockedOrder->status !== OrderStatus::Completed
                || $lockedOrder->purchase_notification_queued_at !== null) {
                return false;
            }

            if (! $lockedOrder->orderItems()->where('purchase_notification_requested', true)->exists()) {
                return false;
            }

            $recipient = config('mail.product_purchase_recipient');

            if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                Log::warning("Product purchase notification for order #{$lockedOrder->id} was not queued because the recipient is invalid.");

                return false;
            }

            $payload = $this->content->for($lockedOrder);
            $queued = $this->managedEmail->handle(
                recipients: $recipient,
                emailTypeKey: 'product-purchase-notification',
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            );

            if ($queued) {
                $lockedOrder->update(['purchase_notification_queued_at' => now()]);
            }

            return $queued;
        });
    }
}
