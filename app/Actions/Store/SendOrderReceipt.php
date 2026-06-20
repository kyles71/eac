<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Mail\OrderReceiptContent;
use Illuminate\Support\Facades\DB;

final readonly class SendOrderReceipt
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private OrderReceiptContent $content,
    ) {}

    public function handle(Order $order, bool $resend = false): bool
    {
        return DB::transaction(function () use ($order, $resend): bool {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null || $lockedOrder->status !== OrderStatus::Completed) {
                return false;
            }

            if (! $resend && $lockedOrder->receipt_queued_at !== null) {
                return false;
            }

            $payload = $this->content->for($lockedOrder);
            $queued = $this->managedEmail->handle(
                recipients: $lockedOrder->user->email,
                emailTypeKey: 'order-receipt',
                tokens: $payload['tokens'],
                slots: $payload['slots'],
                conditions: $payload['conditions'],
            );

            if ($queued) {
                $lockedOrder->update(['receipt_queued_at' => now()]);
            }

            return $queued;
        });
    }
}
