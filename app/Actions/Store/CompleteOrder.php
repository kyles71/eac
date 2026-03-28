<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
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

            // Verify order is still pending or processing
            if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing])) {
                Log::warning("Order #{$order->id} is not pending or processing, skipping completion.", [
                    'status' => $order->status->value,
                ]);

                return false;
            }

            // Atomic capacity check with row-level locks
            /** @var \App\Models\OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                /** @var \App\Models\Product $product */
                $product = $orderItem->product;

                if (! ($product->productable instanceof HasCapacity)) {
                    continue;
                }

                /** @var HasCapacity&\Illuminate\Database\Eloquent\Model $productable */
                $productable = $product->productable;

                // Lock the row to prevent concurrent overselling
                /** @var HasCapacity&\Illuminate\Database\Eloquent\Model $locked */
                $locked = $productable::query()
                    ->lockForUpdate()
                    ->find($productable->id);

                $availableCapacity = $locked->getAvailableCapacity();

                $productableClass = $productable::class;

                if ($orderItem->quantity > $availableCapacity) {
                    Log::warning("Order #{$order->id} failed: insufficient capacity for {$productableClass} #{$productable->id}.", [
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

            // Fulfill order items via their productable contract
            /** @var \App\Models\OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                /** @var \App\Models\Product $product */
                $product = $orderItem->product;

                /** @var \App\Models\User $purchaser */
                $purchaser = $order->user;

                $fulfilled = $product->productable?->fulfillOrderItem($orderItem, $purchaser) ?? false;

                if ($fulfilled) {
                    $orderItem->markFulfilled();
                }
            }

            $order->update(['status' => OrderStatus::Completed]);

            return true;
        });
    }
}
