<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class CompleteOrder
{
    public function __construct(
        private StripeServiceContract $stripeService,
    ) {}

    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $order->loadMissing('orderItems.product.productable');

            // Verify order is still pending
            if ($order->status !== OrderStatus::Pending) {
                Log::warning("Order #{$order->id} is not pending, skipping completion.", [
                    'status' => $order->status->value,
                ]);

                return false;
            }

            // Atomic capacity check with row-level locks
            /** @var \App\Models\OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                /** @var \App\Models\Product $product */
                $product = $orderItem->product;

                if (! ($product->productable instanceof Course)) {
                    continue;
                }

                // Lock the course row to prevent concurrent overselling
                /** @var Course $course */
                $course = Course::query()
                    ->lockForUpdate()
                    ->find($product->productable->id);

                $availableCapacity = $course->availableCapacity();

                if ($orderItem->quantity > $availableCapacity) {
                    Log::warning("Order #{$order->id} failed: insufficient capacity for course #{$course->id}.", [
                        'requested' => $orderItem->quantity,
                        'available' => $availableCapacity,
                    ]);

                    $order->update(['status' => OrderStatus::Failed]);

                    // Refund the payment
                    if ($order->stripe_payment_intent_id !== null) {
                        $this->stripeService->refundPaymentIntent($order->stripe_payment_intent_id);
                    }

                    return false;
                }
            }

            // Create enrollments for course products
            /** @var \App\Models\OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                /** @var \App\Models\Product $product */
                $product = $orderItem->product;

                if (! ($product->productable instanceof Course)) {
                    continue;
                }

                for ($i = 0; $i < $orderItem->quantity; $i++) {
                    Enrollment::query()->create([
                        'course_id' => $product->productable->id,
                        'user_id' => $order->user_id,
                        'student_id' => null,
                    ]);
                }
            }

            $order->update(['status' => OrderStatus::Completed]);

            // Clear the user's cart
            /** @var \App\Models\User $user */
            $user = $order->user;
            $user->cartItems()->delete();

            return true;
        });
    }
}
