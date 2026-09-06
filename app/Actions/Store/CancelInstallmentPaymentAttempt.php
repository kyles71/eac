<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Models\InstallmentPaymentAttempt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CancelInstallmentPaymentAttempt
{
    public function __construct(
        private StripeServiceContract $stripeService,
        private FinalizeInstallmentPaymentAttempt $finalizePaymentAttempt,
    ) {}

    public function handle(InstallmentPaymentAttempt $paymentAttempt, User $user): InstallmentPaymentAttempt
    {
        $paymentAttempt->loadMissing('paymentPlan.order');

        if ($paymentAttempt->paymentPlan->order?->user_id !== $user->id) {
            throw new AuthorizationException('You cannot cancel this payment attempt.');
        }

        if (! $paymentAttempt->status->isActive()) {
            return $paymentAttempt;
        }

        if ($paymentAttempt->status === InstallmentPaymentAttemptStatus::Processing) {
            return $paymentAttempt;
        }

        if ($paymentAttempt->stripe_payment_intent_id !== null) {
            return $this->finalizePaymentAttempt->handle(
                $paymentAttempt,
                $this->stripeService->cancelPaymentIntent($paymentAttempt->stripe_payment_intent_id),
                recordFailure: false,
            );
        }

        $paymentAttempt->update([
            'status' => InstallmentPaymentAttemptStatus::Cancelled,
            'completed_at' => now(),
        ]);

        return $paymentAttempt->refresh();
    }
}
