<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\ProductType;
use App\Models\Order;

final readonly class OrderReceiptContent
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>, conditions: array<string, bool>}
     */
    public function for(Order $order): array
    {
        $order->loadMissing([
            'user',
            'discountCode',
            'orderItems.product',
            'paymentPlan.installments',
        ]);

        $conditions = [
            'course' => false,
            'costume' => false,
            'gift-card' => false,
            'standalone' => false,
        ];

        foreach ($order->orderItems as $orderItem) {
            $condition = match (ProductType::fromProductableType($orderItem->product->productable_type)) {
                ProductType::Course => 'course',
                ProductType::Costume => 'costume',
                ProductType::GiftCardType => 'gift-card',
                ProductType::Standalone => 'standalone',
                ProductType::Any => null,
            };

            if ($condition !== null) {
                $conditions[$condition] = true;
            }
        }

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $order->user->first_name,
                'order.number' => (string) $order->id,
                'order.date' => $order->created_at->format('F j, Y'),
                'order.total' => $order->formattedTotal(),
            ],
            'slots' => [
                'order-details' => view('mail.order-receipt-details', ['order' => $order])->render(),
            ],
            'conditions' => $conditions,
        ];
    }
}
