<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class CancelOrder
{
    public function __construct(
        private StripeServiceContract $stripeService,
    ) {}

    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $order = Order::query()->lockForUpdate()->find($order->id);

            if ($order === null || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing])) {
                return false;
            }

            /** @var \App\Models\User $user */
            $user = $order->user;

            // Reverse store credit
            if ($order->credit_applied > 0) {
                $user->adjustCredit(
                    $order->credit_applied,
                    CreditTransactionType::Refund,
                    $order,
                    "Reversed credit for cancelled order #{$order->id}",
                );
            }

            // Reverse restricted credit
            if ($order->restricted_credit_applied > 0) {
                $user->reverseRestrictedCredit($order->restricted_credit_applied);

                $user->adjustCredit(
                    0,
                    CreditTransactionType::Refund,
                    $order,
                    "Reversed restricted credit for cancelled order #{$order->id}",
                );
            }

            // Decrement discount code usage
            if ($order->discount_code_id !== null) {
                $order->loadMissing('discountCode');

                if ($order->discountCode !== null) {
                    $order->discountCode->decrement('times_used');
                }
            }

            // Cancel Stripe PaymentIntent (best-effort)
            if ($order->stripe_payment_intent_id !== null) {
                try {
                    $this->stripeService->cancelPaymentIntent($order->stripe_payment_intent_id);
                } catch (Exception $e) {
                    Log::warning("Failed to cancel Stripe PaymentIntent for order #{$order->id}: {$e->getMessage()}");
                }
            }

            $order->update(['status' => OrderStatus::Cancelled]);

            return true;
        });
    }
}
