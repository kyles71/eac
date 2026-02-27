<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\PaymentPlanMethod;
use App\Models\Installment;
use Exception;
use Illuminate\Support\Facades\Log;

final readonly class ProcessInstallments
{
    public function __construct(
        private StripeServiceContract $stripeService,
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
            ->with('paymentPlan')
            ->get();

        // Get retryable installments (failed + retry_count < 3)
        $retryableInstallments = Installment::query()
            ->retryable()
            ->with('paymentPlan')
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
            if ($paymentPlan->method === PaymentPlanMethod::AutoCharge) {
                return $this->processAutoCharge($installment, $paymentPlan);
            }

            return $this->processManualInvoice($installment, $paymentPlan);
        } catch (Exception $e) {
            Log::error("Failed to process installment #{$installment->id}: {$e->getMessage()}");
            $installment->markFailed();

            return false;
        }
    }

    private function processAutoCharge(Installment $installment, \App\Models\PaymentPlan $paymentPlan): bool
    {
        if ($paymentPlan->stripe_customer_id === null || $paymentPlan->stripe_payment_method_id === null) {
            Log::warning("Payment plan #{$paymentPlan->id} missing Stripe credentials for auto-charge.");
            $installment->markFailed();

            return false;
        }

        $paymentIntent = $this->stripeService->chargePaymentMethod(
            customerId: $paymentPlan->stripe_customer_id,
            paymentMethodId: $paymentPlan->stripe_payment_method_id,
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

    private function processManualInvoice(Installment $installment, \App\Models\PaymentPlan $paymentPlan): bool
    {
        if ($paymentPlan->stripe_customer_id === null) {
            Log::warning("Payment plan #{$paymentPlan->id} missing Stripe customer ID for invoice.");
            $installment->markFailed();

            return false;
        }

        $invoice = $this->stripeService->createAndSendInvoice(
            customerId: $paymentPlan->stripe_customer_id,
            amount: $installment->amount,
            description: "Installment #{$installment->installment_number} for Order #{$paymentPlan->order_id}",
            metadata: [
                'installment_id' => (string) $installment->id,
                'payment_plan_id' => (string) $paymentPlan->id,
                'order_id' => (string) $paymentPlan->order_id,
            ],
        );

        $installment->update([
            'stripe_invoice_id' => $invoice->id,
        ]);

        Log::info("Invoice sent for installment #{$installment->id}.", [
            'invoice_id' => $invoice->id,
        ]);

        // Invoice is sent but not yet paid — webhook will confirm payment
        return true;
    }
}
