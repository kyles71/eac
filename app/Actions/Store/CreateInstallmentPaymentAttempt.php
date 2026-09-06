<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\PaymentPlan;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateInstallmentPaymentAttempt
{
    /**
     * @param  list<int|string>  $installmentIds
     */
    public function handle(
        PaymentPlan $paymentPlan,
        array $installmentIds,
        InstallmentPaymentAttemptOrigin $origin,
        ?User $initiatedBy = null,
        bool $useForFuture = false,
        ?string $stripePaymentMethodId = null,
    ): InstallmentPaymentAttempt {
        $installmentIds = collect($installmentIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($installmentIds === []) {
            throw new InvalidArgumentException('Select at least one missed installment.');
        }

        return DB::transaction(function () use (
            $paymentPlan,
            $installmentIds,
            $origin,
            $initiatedBy,
            $useForFuture,
            $stripePaymentMethodId,
        ): InstallmentPaymentAttempt {
            /** @var PaymentPlan $lockedPaymentPlan */
            $lockedPaymentPlan = PaymentPlan::query()
                ->with('order.user')
                ->lockForUpdate()
                ->findOrFail($paymentPlan->id);

            $this->authorize($lockedPaymentPlan, $origin, $initiatedBy);

            if (in_array($lockedPaymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                throw new DomainException('This payment plan is no longer collectible.');
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, Installment> $installments */
            $installments = $lockedPaymentPlan->installments()
                ->whereKey($installmentIds)
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            if ($installments->count() !== count($installmentIds)) {
                throw new InvalidArgumentException('One or more selected installments do not belong to this payment plan.');
            }

            $eligibleStatuses = $origin === InstallmentPaymentAttemptOrigin::Scheduled
                ? [InstallmentStatus::Pending, InstallmentStatus::Failed]
                : [InstallmentStatus::Failed, InstallmentStatus::Overdue];

            if ($installments->contains(fn (Installment $installment): bool => ! in_array($installment->status, $eligibleStatuses, true))) {
                throw new DomainException('Only eligible missed installments can be collected.');
            }

            $hasRefundBlock = Installment::query()
                ->whereKey($installmentIds)
                ->notBlockedByRefundCancellation()
                ->count() !== count($installmentIds);

            if ($hasRefundBlock) {
                throw new DomainException('A refund currently prevents collection for this payment plan.');
            }

            $hasActiveAttempt = InstallmentPaymentAttempt::query()
                ->active()
                ->whereHas('allocations', fn ($query) => $query->whereIn('installment_id', $installmentIds))
                ->exists();

            if ($hasActiveAttempt) {
                throw new DomainException('A payment for one or more selected installments is already in progress.');
            }

            $stripeCustomerId = $lockedPaymentPlan->order?->user?->stripe_id
                ?? $lockedPaymentPlan->stripe_customer_id;

            if ($origin !== InstallmentPaymentAttemptOrigin::Customer
                && (! is_string($stripeCustomerId) || $stripeCustomerId === '')) {
                throw new DomainException('This payment plan does not have Stripe customer information.');
            }

            if ($origin !== InstallmentPaymentAttemptOrigin::Customer
                && (! is_string($stripePaymentMethodId) || $stripePaymentMethodId === '')) {
                throw new DomainException('This payment plan does not have an assigned payment method.');
            }

            $paymentAttempt = InstallmentPaymentAttempt::query()->create([
                'payment_plan_id' => $lockedPaymentPlan->id,
                'initiated_by_user_id' => $initiatedBy?->id,
                'idempotency_key' => (string) Str::uuid(),
                'origin' => $origin,
                'status' => InstallmentPaymentAttemptStatus::Pending,
                'total_amount' => (int) $installments->sum('amount'),
                'stripe_customer_id' => $stripeCustomerId,
                'stripe_payment_method_id' => $stripePaymentMethodId,
                'use_for_future' => $useForFuture,
            ]);

            foreach ($installments as $installment) {
                $paymentAttempt->allocations()->create([
                    'installment_id' => $installment->id,
                    'amount' => $installment->amount,
                ]);
            }

            return $paymentAttempt->load(['allocations.installment', 'paymentPlan.order.user']);
        }, attempts: 3);
    }

    private function authorize(
        PaymentPlan $paymentPlan,
        InstallmentPaymentAttemptOrigin $origin,
        ?User $initiatedBy,
    ): void {
        if ($origin === InstallmentPaymentAttemptOrigin::Scheduled) {
            return;
        }

        if (! $initiatedBy instanceof User) {
            throw new AuthorizationException('A payment initiator is required.');
        }

        if ($origin === InstallmentPaymentAttemptOrigin::Customer) {
            if ($paymentPlan->order?->user_id !== $initiatedBy->id) {
                throw new AuthorizationException('You cannot pay this payment plan.');
            }

            return;
        }

        Gate::forUser($initiatedBy)->authorize('retryPayment', $paymentPlan);
    }
}
