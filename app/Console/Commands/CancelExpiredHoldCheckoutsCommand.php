<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Store\CancelOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Order;
use Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('course-holds:cancel-expired-checkouts')]
#[Description('Cancel unpaid class-hold checkouts after their 30-minute lease')]
final class CancelExpiredHoldCheckoutsCommand extends Command
{
    public function handle(CancelOrder $cancelOrder, StripeServiceContract $stripeService): int
    {
        $cancelled = 0;

        Order::query()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing])
            ->whereNotNull('hold_checkout_expires_at')
            ->where('hold_checkout_expires_at', '<=', now())
            ->each(function (Order $order) use ($cancelOrder, $stripeService, &$cancelled): void {
                if ($order->stripe_payment_intent_id !== null) {
                    try {
                        $paymentIntent = $stripeService->retrievePaymentIntent($order->stripe_payment_intent_id);

                        if (in_array($paymentIntent->status, ['processing', 'succeeded'], true)) {
                            return;
                        }
                    } catch (Exception $exception) {
                        Log::warning("Unable to inspect expired hold checkout for order #{$order->id}: {$exception->getMessage()}");

                        return;
                    }
                }

                if ($cancelOrder->handle($order)) {
                    $cancelled++;
                }
            });

        $this->info("Cancelled {$cancelled} expired class hold checkout(s).");

        return self::SUCCESS;
    }
}
