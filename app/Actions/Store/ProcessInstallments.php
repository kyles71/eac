<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Throwable;

final class ProcessInstallments
{
    /**
     * @var array<string, string|null>
     */
    private array $defaultPaymentMethodIds = [];

    public function __construct(
        private readonly StripeServiceContract $stripeService,
        private readonly SendInstallmentPaymentEmail $paymentEmail,
        private readonly SendPastDueInstallmentNotification $pastDueNotification,
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
        } catch (Throwable $e) {
            Log::error("Failed to process installment #{$installment->id}: {$e->getMessage()}");
            $failureReason = $this->customerFailureReason($e);
            $failureCode = $this->failureCode($e);
            $installment->markFailed(
                stripeStatus: 'failed',
                failureReason: $failureReason,
                failureCode: $failureCode,
            );
            $this->queuePaymentEmail(
                installment: $installment,
                successful: false,
                stripeStatus: 'failed',
                stripeCustomerId: $paymentPlan->order->user->stripe_id ?? $paymentPlan->stripe_customer_id,
                stripePaymentMethodId: $paymentPlan->stripe_payment_method_id,
                failureReason: $failureReason,
                failureCode: $failureCode,
            );
            $this->queuePastDueNotification($installment);

            return false;
        }
    }

    private function processAutoCharge(Installment $installment, PaymentPlan $paymentPlan): bool
    {
        $customerId = $paymentPlan->order->user->stripe_id ?? $paymentPlan->stripe_customer_id;

        if ($customerId === null) {
            Log::warning("Payment plan #{$paymentPlan->id} missing Stripe credentials for auto-charge.");
            $this->markFailedAndNotify(
                installment: $installment,
                stripeCustomerId: null,
                stripePaymentMethodId: $paymentPlan->stripe_payment_method_id,
                failureReason: 'Your payment plan is missing its Stripe customer information.',
                failureCode: 'missing_customer',
            );

            return false;
        }

        $paymentMethodId = $paymentPlan->stripe_payment_method_id
            ?? $this->defaultPaymentMethodId($customerId);

        if ($paymentMethodId === null) {
            Log::warning("Payment plan #{$paymentPlan->id} has no assigned or default Stripe payment method.");
            $this->markFailedAndNotify(
                installment: $installment,
                stripeCustomerId: $customerId,
                stripePaymentMethodId: null,
                failureReason: 'No payment method is available for this payment plan.',
                failureCode: 'missing_payment_method',
            );

            return false;
        }

        try {
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
        } catch (Throwable $exception) {
            Log::error("Failed to charge installment #{$installment->id}: {$exception->getMessage()}");
            $this->markFailedAndNotify(
                installment: $installment,
                stripeCustomerId: $customerId,
                stripePaymentMethodId: $paymentMethodId,
                failureReason: $this->customerFailureReason($exception),
                failureCode: $this->failureCode($exception),
            );

            return false;
        }

        if ($paymentIntent->status === 'succeeded') {
            $installment->markPaid(stripePaymentIntentId: $paymentIntent->id);
            Log::info("Installment #{$installment->id} paid via auto-charge.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            $this->queuePaymentEmail(
                installment: $installment,
                successful: true,
                stripeStatus: $paymentIntent->status,
                stripePaymentIntentId: $paymentIntent->id,
                stripeCustomerId: $customerId,
                stripePaymentMethodId: $paymentMethodId,
            );

            return true;
        }

        Log::warning("Auto-charge for installment #{$installment->id} did not succeed immediately.", [
            'status' => $paymentIntent->status,
        ]);
        $this->markFailedAndNotify(
            installment: $installment,
            stripeStatus: $paymentIntent->status,
            stripePaymentIntentId: $paymentIntent->id,
            stripeCustomerId: $customerId,
            stripePaymentMethodId: $paymentMethodId,
            failureReason: "Stripe returned a {$paymentIntent->status} status instead of completing the payment.",
            failureCode: $paymentIntent->status,
        );

        return false;
    }

    private function markFailedAndNotify(
        Installment $installment,
        ?string $stripeStatus = 'failed',
        ?string $stripePaymentIntentId = null,
        ?string $stripeCustomerId = null,
        ?string $stripePaymentMethodId = null,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): void {
        $installment->markFailed(
            stripeStatus: $stripeStatus,
            stripePaymentIntentId: $stripePaymentIntentId,
            failureReason: $failureReason,
            failureCode: $failureCode,
        );
        $this->queuePaymentEmail(
            installment: $installment,
            successful: false,
            stripeStatus: $stripeStatus,
            stripePaymentIntentId: $stripePaymentIntentId,
            stripeCustomerId: $stripeCustomerId,
            stripePaymentMethodId: $stripePaymentMethodId,
            failureReason: $failureReason,
            failureCode: $failureCode,
        );
        $this->queuePastDueNotification($installment);
    }

    private function queuePaymentEmail(
        Installment $installment,
        bool $successful,
        ?string $stripeStatus = null,
        ?string $stripePaymentIntentId = null,
        ?string $stripeCustomerId = null,
        ?string $stripePaymentMethodId = null,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): void {
        try {
            $this->paymentEmail->handle(
                installment: $installment,
                successful: $successful,
                stripeStatus: $stripeStatus,
                stripePaymentIntentId: $stripePaymentIntentId,
                stripeCustomerId: $stripeCustomerId,
                stripePaymentMethodId: $stripePaymentMethodId,
                failureReason: $failureReason,
                failureCode: $failureCode,
            );
        } catch (Throwable $exception) {
            Log::error("Failed to queue the payment result email for installment #{$installment->id}.", [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function customerFailureReason(Throwable $exception): string
    {
        if ($exception instanceof CardException) {
            $message = $exception->getError()?->message;

            if (is_string($message) && $message !== '') {
                return $message;
            }

            return $exception->getMessage();
        }

        return 'We could not process this payment. Please review the payment method on your account.';
    }

    private function queuePastDueNotification(Installment $installment): void
    {
        if ($installment->status !== InstallmentStatus::Overdue) {
            return;
        }

        try {
            $this->pastDueNotification->handle($installment);
        } catch (Throwable $exception) {
            Log::error("Failed to queue the past-due notification for installment #{$installment->id}.", [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function failureCode(Throwable $exception): ?string
    {
        if ($exception instanceof CardException && is_string($exception->getDeclineCode())) {
            return $exception->getDeclineCode();
        }

        if ($exception instanceof ApiErrorException && is_string($exception->getStripeCode())) {
            return $exception->getStripeCode();
        }

        return null;
    }

    private function defaultPaymentMethodId(string $customerId): ?string
    {
        if (! array_key_exists($customerId, $this->defaultPaymentMethodIds)) {
            $this->defaultPaymentMethodIds[$customerId] = $this->stripeService->getDefaultPaymentMethodId($customerId);
        }

        return $this->defaultPaymentMethodIds[$customerId];
    }
}
