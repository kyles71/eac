<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Models\Course;
use App\Models\CourseHoldSeat;
use App\Models\Order;
use App\Models\OrderItem;
use InvalidArgumentException;

final readonly class ClaimCourseHoldSeatsForOrder
{
    public function handle(Order $order): void
    {
        $order->loadMissing(['user', 'orderItems.product.productable']);
        $usesHold = false;

        /** @var OrderItem $orderItem */
        foreach ($order->orderItems->whereNotNull('course_hold_id') as $orderItem) {
            $productable = $orderItem->product->productable;

            if (! $productable instanceof Course) {
                throw new InvalidArgumentException('Held order items must be linked to a class.');
            }

            $seats = CourseHoldSeat::query()
                ->where('course_hold_id', $orderItem->course_hold_id)
                ->where('course_id', $productable->id)
                ->where('locked_unit_price', $orderItem->unit_price)
                ->whereHas('hold', fn ($query) => $query
                    ->where('user_id', $order->user_id)
                    ->where('expires_at', '>', now()))
                ->claimable()
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($orderItem->quantity)
                ->get();

            if ($seats->count() !== $orderItem->quantity) {
                throw new InvalidArgumentException("The held seats for \"{$productable->name}\" are no longer available.");
            }

            CourseHoldSeat::query()
                ->whereKey($seats->modelKeys())
                ->update([
                    'claimed_order_item_id' => $orderItem->id,
                    'updated_at' => now(),
                ]);

            $usesHold = true;
        }

        if ($usesHold) {
            $order->update([
                'hold_checkout_expires_at' => now()->addMinutes(30),
            ]);
        }
    }
}
