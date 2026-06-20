<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;

final readonly class ProductPurchaseNotificationContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(Order $order): array
    {
        $order->loadMissing([
            'user',
            'orderItems.product',
            'orderItems.questionAnswers',
        ]);

        $notificationItems = $order->orderItems
            ->where('purchase_notification_requested', true)
            ->values();

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'purchaser.name' => $order->user->fullName,
                'purchaser.email' => $order->user->email,
                'order.number' => (string) $order->id,
                'order.date' => $order->created_at->format('F j, Y'),
                'order.total' => $order->formattedTotal(),
            ],
            'slots' => [
                'purchase-details' => view('mail.product-purchase-notification-details', [
                    'order' => $order,
                    'orderItems' => $notificationItems,
                ])->render(),
            ],
        ];
    }
}
