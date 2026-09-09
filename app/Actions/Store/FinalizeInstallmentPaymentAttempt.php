<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentPaymentAttemptEmailStatus;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Jobs\SendInstallmentPaymentAttemptResultEmail;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use DomainException;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Throwable;

final readonly class FinalizeInstallmentPaymentAttempt
{
    public function __construct(
        private SendPastDueInstallmentNotification $pastDueNotification,
    ) {}

    public function handle(
        InstallmentPaymentAttempt $paymentAttempt,
        PaymentIntent $paymentIntent,
        bool $recordFailure = true,
    ): InstallmentPaymentAttempt {
        $paymentAttempt = DB::transaction(function () use ($paymentAttempt, $paymentIntent, $recordFailure): InstallmentPaymentAttempt {
            /** @var InstallmentPaymentAttempt $lockedAttempt */
            $lockedAttempt = InstallmentPaymentAttempt::query()
                ->with(['allocations.installment', 'paymentPlan.order.user'])
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->id);

            $this->validatePaymentIntent($lockedAttempt, $paymentIntent);
            $this->lockAllocatedInstallments($lockedAttempt);

            if ($lockedAttempt->status === InstallmentPaymentAttemptStatus::Succeeded
                && $paymentIntent->status !== 'succeeded') {
                return $lockedAttempt;
            }

            $paymentMethodId = $this->stringValue($paymentIntent->payment_method ?? null);
            $status = (string) $paymentIntent->status;
            $attributes = [
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_payment_method_id' => $paymentMethodId ?? $lockedAttempt->stripe_payment_method_id,
                'stripe_status' => $status,
            ];

            if ($lockedAttempt->status === InstallmentPaymentAttemptStatus::Cancelled
                && ! in_array($status, ['succeeded', 'processing'], true)) {
                $lockedAttempt->update([
                    ...$attributes,
                    'status' => InstallmentPaymentAttemptStatus::Cancelled,
                    'completed_at' => $lockedAttempt->completed_at ?? now(),
                ]);

                return $lockedAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
            }

            if ($status === 'succeeded') {
                if ($lockedAttempt->allocations->contains(
                    fn ($allocation): bool => $allocation->installment->status === InstallmentStatus::Cancelled,
                )) {
                    report(new DomainException(
                        "Stripe payment {$paymentIntent->id} succeeded for payment attempt #{$lockedAttempt->id}, but an allocated installment is cancelled.",
                    ));

                    $lockedAttempt->update([
                        ...$attributes,
                        'status' => InstallmentPaymentAttemptStatus::Error,
                        'failure_reason' => 'Stripe reported a successful payment for a cancelled installment. Review the payment in Stripe and issue a refund if needed.',
                        'completed_at' => $lockedAttempt->completed_at ?? now(),
                    ]);

                    return $lockedAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
                }

                if ($lockedAttempt->status !== InstallmentPaymentAttemptStatus::Succeeded) {
                    foreach ($lockedAttempt->allocations as $allocation) {
                        $installment = $allocation->installment;

                        if ($installment->status !== InstallmentStatus::Paid) {
                            $installment->markPaid(stripePaymentIntentId: $paymentIntent->id);
                        }
                    }

                    if ($lockedAttempt->use_for_future && $paymentMethodId !== null) {
                        $lockedAttempt->paymentPlan->update([
                            'stripe_payment_method_id' => $paymentMethodId,
                        ]);
                    }
                }

                $attributes['status'] = InstallmentPaymentAttemptStatus::Succeeded;
                $attributes['failure_reason'] = null;
                $attributes['failure_code'] = null;
                $attributes['advice_code'] = null;
                $attributes['completed_at'] = $lockedAttempt->completed_at ?? now();
                $attributes['success_email_status'] = $lockedAttempt->success_email_status
                    ?? InstallmentPaymentAttemptEmailStatus::Pending;
            } elseif ($status === 'processing') {
                $attributes['status'] = InstallmentPaymentAttemptStatus::Processing;
            } elseif ($status === 'requires_action') {
                $attributes['status'] = InstallmentPaymentAttemptStatus::RequiresAction;
            } elseif ($status === 'requires_payment_method') {
                $attributes['status'] = $lockedAttempt->origin === InstallmentPaymentAttemptOrigin::Customer
                    ? InstallmentPaymentAttemptStatus::RequiresPaymentMethod
                    : InstallmentPaymentAttemptStatus::Failed;
                $hasPaymentError = data_get($paymentIntent, 'last_payment_error') !== null;
                $attributes = $hasPaymentError
                    ? [...$attributes, ...$this->failureAttributes($paymentIntent)]
                    : [
                        ...$attributes,
                        'failure_reason' => null,
                        'failure_code' => null,
                        'advice_code' => null,
                    ];

                if ($recordFailure
                    && $lockedAttempt->failure_recorded_at === null
                    && $hasPaymentError) {
                    $this->recordInstallmentFailures($lockedAttempt, $paymentIntent);
                    $attributes['failure_recorded_at'] = now();
                }

                if ($attributes['status'] === InstallmentPaymentAttemptStatus::Failed) {
                    $attributes['completed_at'] = $lockedAttempt->completed_at ?? now();
                }

                if (($attributes['failure_recorded_at'] ?? $lockedAttempt->failure_recorded_at) !== null) {
                    $attributes['failure_email_status'] = $lockedAttempt->failure_email_status
                        ?? InstallmentPaymentAttemptEmailStatus::Pending;
                }
            } elseif ($status === 'canceled') {
                $attributes['status'] = InstallmentPaymentAttemptStatus::Cancelled;
                $attributes['completed_at'] = $lockedAttempt->completed_at ?? now();
            } else {
                $attributes['status'] = InstallmentPaymentAttemptStatus::Pending;
            }

            $lockedAttempt->update($attributes);

            return $lockedAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
        }, attempts: 3);

        $this->sendResultNotifications($paymentAttempt);

        return $paymentAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
    }

    public function failWithoutPaymentIntent(
        InstallmentPaymentAttempt $paymentAttempt,
        string $failureReason,
        ?string $failureCode = null,
        ?string $adviceCode = null,
    ): InstallmentPaymentAttempt {
        $paymentAttempt = DB::transaction(function () use ($paymentAttempt, $failureReason, $failureCode, $adviceCode): InstallmentPaymentAttempt {
            /** @var InstallmentPaymentAttempt $lockedAttempt */
            $lockedAttempt = InstallmentPaymentAttempt::query()
                ->with(['allocations.installment', 'paymentPlan.order.user'])
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->id);
            $this->lockAllocatedInstallments($lockedAttempt);

            if (! $lockedAttempt->status->isActive()) {
                return $lockedAttempt;
            }

            if ($lockedAttempt->failure_recorded_at === null) {
                foreach ($lockedAttempt->allocations as $allocation) {
                    $installment = $allocation->installment;

                    if (in_array($installment->status, [InstallmentStatus::Paid, InstallmentStatus::Cancelled], true)) {
                        continue;
                    }

                    $installment->markFailed(
                        failureReason: $failureReason,
                        failureCode: $failureCode,
                    );
                }
            }

            $lockedAttempt->update([
                'status' => InstallmentPaymentAttemptStatus::Failed,
                'failure_reason' => $failureReason,
                'failure_code' => $failureCode,
                'advice_code' => $adviceCode,
                'failure_recorded_at' => $lockedAttempt->failure_recorded_at ?? now(),
                'failure_email_status' => $lockedAttempt->failure_email_status
                    ?? InstallmentPaymentAttemptEmailStatus::Pending,
                'completed_at' => $lockedAttempt->completed_at ?? now(),
            ]);

            return $lockedAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
        }, attempts: 3);

        $this->sendResultNotifications($paymentAttempt);

        return $paymentAttempt->refresh();
    }

    public function recordOperationalError(
        InstallmentPaymentAttempt $paymentAttempt,
        string $failureReason,
        ?string $failureCode = null,
    ): InstallmentPaymentAttempt {
        return DB::transaction(function () use ($paymentAttempt, $failureReason, $failureCode): InstallmentPaymentAttempt {
            /** @var InstallmentPaymentAttempt $lockedAttempt */
            $lockedAttempt = InstallmentPaymentAttempt::query()
                ->with(['allocations.installment', 'paymentPlan.order.user'])
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->id);

            if (! $lockedAttempt->status->isActive()) {
                return $lockedAttempt;
            }

            $lockedAttempt->update([
                'status' => InstallmentPaymentAttemptStatus::Error,
                'failure_reason' => $failureReason,
                'failure_code' => $failureCode,
                'completed_at' => $lockedAttempt->completed_at ?? now(),
            ]);

            return $lockedAttempt->refresh()->load(['allocations.installment', 'paymentPlan.order.user']);
        }, attempts: 3);
    }

    private function validatePaymentIntent(
        InstallmentPaymentAttempt $paymentAttempt,
        PaymentIntent $paymentIntent,
    ): void {
        if ($paymentAttempt->stripe_payment_intent_id !== null
            && $paymentAttempt->stripe_payment_intent_id !== $paymentIntent->id) {
            throw new DomainException('The Stripe payment does not match this payment attempt.');
        }

        if ((int) $paymentIntent->amount !== $paymentAttempt->total_amount
            || mb_strtolower((string) $paymentIntent->currency) !== 'usd'
            || $this->stringValue($paymentIntent->customer ?? null) !== $paymentAttempt->stripe_customer_id
            || $this->stringValue(data_get($paymentIntent, 'metadata.payment_attempt_id')) !== (string) $paymentAttempt->id) {
            throw new DomainException('The Stripe payment details do not match this payment attempt.');
        }
    }

    private function lockAllocatedInstallments(InstallmentPaymentAttempt $paymentAttempt): void
    {
        $installments = Installment::query()
            ->whereKey($paymentAttempt->allocations->pluck('installment_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($paymentAttempt->allocations as $allocation) {
            /** @var Installment $installment */
            $installment = $installments->get($allocation->installment_id);
            $allocation->setRelation('installment', $installment);
        }
    }

    /** @return array<string, string|null> */
    private function failureAttributes(PaymentIntent $paymentIntent): array
    {
        return [
            'failure_reason' => $this->stringValue(data_get($paymentIntent, 'last_payment_error.message'))
                ?? 'We could not process this payment. Please review the payment method on your account.',
            'failure_code' => $this->stringValue(data_get($paymentIntent, 'last_payment_error.decline_code'))
                ?? $this->stringValue(data_get($paymentIntent, 'last_payment_error.code')),
            'advice_code' => $this->stringValue(data_get($paymentIntent, 'latest_charge.outcome.advice_code'))
                ?? $this->stringValue(data_get($paymentIntent, 'last_payment_error.advice_code')),
        ];
    }

    private function recordInstallmentFailures(
        InstallmentPaymentAttempt $paymentAttempt,
        PaymentIntent $paymentIntent,
    ): void {
        $failure = $this->failureAttributes($paymentIntent);

        foreach ($paymentAttempt->allocations as $allocation) {
            $installment = $allocation->installment;

            if (in_array($installment->status, [InstallmentStatus::Paid, InstallmentStatus::Cancelled], true)) {
                continue;
            }

            $installment->markFailed(
                stripeStatus: (string) $paymentIntent->status,
                stripePaymentIntentId: $paymentIntent->id,
                failureReason: $failure['failure_reason'],
                failureCode: $failure['failure_code'],
            );
        }
    }

    private function sendResultNotifications(InstallmentPaymentAttempt $paymentAttempt): void
    {
        if ($paymentAttempt->success_email_status === InstallmentPaymentAttemptEmailStatus::Pending) {
            $this->dispatchResultEmail($paymentAttempt, successful: true);
        }

        if ($paymentAttempt->failure_email_status === InstallmentPaymentAttemptEmailStatus::Pending) {
            $this->dispatchResultEmail($paymentAttempt, successful: false);
        }

        if ($paymentAttempt->failure_recorded_at === null) {
            return;
        }

        foreach ($paymentAttempt->allocations as $allocation) {
            try {
                if ($allocation->installment->refresh()->status === InstallmentStatus::Overdue) {
                    $this->pastDueNotification->handle($allocation->installment);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function dispatchResultEmail(
        InstallmentPaymentAttempt $paymentAttempt,
        bool $successful,
    ): void {
        try {
            SendInstallmentPaymentAttemptResultEmail::dispatch(
                $paymentAttempt->id,
                $paymentAttempt->idempotency_key,
                $successful,
            )
                ->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $id = data_get($value, 'id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
