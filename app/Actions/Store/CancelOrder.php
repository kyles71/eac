<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\CourseHolds\ReleaseCourseHoldOrderClaims;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CreditLedgerService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class CancelOrder
{
    public function __construct(
        private StripeServiceContract $stripeService,
        private CreditLedgerService $creditLedger,
        private ReleaseCourseHoldOrderClaims $releaseCourseHoldOrderClaims,
    ) {}

    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $order = Order::query()->lockForUpdate()->find($order->id);

            if ($order === null || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing])) {
                return false;
            }

            if ($order->credit_applied > 0 || $order->restricted_credit_applied > 0) {
                $this->creditLedger->restoreOrder($order);
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
            $this->releaseCourseHoldOrderClaims->handle($order);

            return true;
        });
    }
}
