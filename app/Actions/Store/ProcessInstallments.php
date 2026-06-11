<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Exception;
use Illuminate\Support\Facades\Log;

final class ProcessInstallments
{
    /**
     * @var array<string, string|null>
     */
    private array $defaultPaymentMethodIds = [];

    public function __construct(
        private readonly StripeServiceContract $stripeService,
    ) {}

    /**
     * Process all due and retryable installments.
     *
     * @return array{processed: int, succeeded: int, failed: int}
     */
    public function handle(): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        // Get due installments (pending + due date <= today)
        $dueInstallments = Installment::query()
            ->due()
            ->with('paymentPlan.order.user')
            ->get();

        // Get retryable installments (failed + retry_count < 3)
        $retryableInstallments = Installment::query()
            ->retryable()
            ->with('paymentPlan.order.user')
            ->get();

        $allInstallments = $dueInstallments->merge($retryableInstallments);

        /** @var Installment $installment */
        foreach ($allInstallments as $installment) {
            $processed++;
            $result = $this->processInstallment($installment);

            if ($result) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    private function processInstallment(Installment $installment): bool
    {
        $paymentPlan = $installment->paymentPlan;

        if ($paymentPlan === null) {
            Log::warning("Installment #{$installment->id} has no payment plan.");

            return false;
        }

        try {
            return $this->processAutoCharge($installment, $paymentPlan);
        } catch (Exception $e) {
            Log::error("Failed to process installment #{$installment->id}: {$e->getMessage()}");
            $installment->markFailed();

            return false;
        }
    }

    private function processAutoCharge(Installment $installment, PaymentPlan $paymentPlan): bool
    {
        $customerId = $paymentPlan->order->user->stripe_id ?? $paymentPlan->stripe_customer_id;

        if ($customerId === null) {
            Log::warning("Payment plan #{$paymentPlan->id} missing Stripe credentials for auto-charge.");
            $installment->markFailed();

            return false;
        }

        $paymentMethodId = $paymentPlan->stripe_payment_method_id
            ?? $this->defaultPaymentMethodId($customerId);

        if ($paymentMethodId === null) {
            Log::warning("Payment plan #{$paymentPlan->id} has no assigned or default Stripe payment method.");
            $installment->markFailed();

            return false;
        }

        $paymentIntent = $this->stripeService->chargePaymentMethod(
            customerId: $customerId,
            paymentMethodId: $paymentMethodId,
            amount: $installment->amount,
            description: "Installment #{$installment->installment_number} for Order #{$paymentPlan->order_id}",
            metadata: [
                'installment_id' => (string) $installment->id,
                'payment_plan_id' => (string) $paymentPlan->id,
                'order_id' => (string) $paymentPlan->order_id,
            ],
        );

        if ($paymentIntent->status === 'succeeded') {
            $installment->markPaid(stripePaymentIntentId: $paymentIntent->id);
            Log::info("Installment #{$installment->id} paid via auto-charge.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);

            return true;
        }

        Log::warning("Auto-charge for installment #{$installment->id} did not succeed immediately.", [
            'status' => $paymentIntent->status,
        ]);
        $installment->markFailed();

        return false;
    }

    private function defaultPaymentMethodId(string $customerId): ?string
    {
        if (! array_key_exists($customerId, $this->defaultPaymentMethodIds)) {
            $this->defaultPaymentMethodIds[$customerId] = $this->stripeService->getDefaultPaymentMethodId($customerId);
        }

        return $this->defaultPaymentMethodIds[$customerId];
    }
}
