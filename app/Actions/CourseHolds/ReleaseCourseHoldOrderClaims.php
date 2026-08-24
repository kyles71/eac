<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Models\CourseHoldSeat;
use App\Models\Order;

final readonly class ReleaseCourseHoldOrderClaims
{
    public function handle(Order $order): int
    {
        $orderItemIds = $order->orderItems()->pluck('id');

        if ($orderItemIds->isEmpty()) {
            return 0;
        }

        return CourseHoldSeat::query()
            ->whereIn('claimed_order_item_id', $orderItemIds)
            ->whereDoesntHave('enrollment')
            ->update([
                'claimed_order_item_id' => null,
                'updated_at' => now(),
            ]);
    }
}
