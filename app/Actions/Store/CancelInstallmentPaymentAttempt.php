<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Models\InstallmentPaymentAttempt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($paymentAttempt): InstallmentPaymentAttempt {
            /** @var InstallmentPaymentAttempt $lockedAttempt */
            $lockedAttempt = InstallmentPaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->id);

            if (! $lockedAttempt->status->isActive()
                || $lockedAttempt->status === InstallmentPaymentAttemptStatus::Processing
                || $lockedAttempt->stripe_payment_intent_id !== null) {
                return $lockedAttempt;
            }

            $lockedAttempt->update([
                'status' => InstallmentPaymentAttemptStatus::Cancelled,
                'completed_at' => now(),
            ]);

            return $lockedAttempt->refresh();
        }, attempts: 3);
    }
}
