<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Actions\Mail\SendRecurringPrivateLessonEmail;
use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonCoverageStatus;
use App\Enums\RecurringPrivateLessonResolutionType;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\RecurringPrivateLessonCoverage;
use App\Models\User;
use App\Services\CreditLedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class RemoveRecurringPrivateLessonCharge
{
    public function __construct(
        private SendRecurringPrivateLessonEmail $emails,
        private CreditLedgerService $creditLedger,
        private StripeServiceContract $stripeService,
    ) {}

    public function handle(
        RecurringPrivateLessonCharge $charge,
        User $removedBy,
        string $reason,
        ?RecurringPrivateLessonResolutionType $paymentResolution = null,
    ): void {
        $reason = mb_trim($reason);

        if (! $removedBy->hasAnyRole(['owner', 'super_admin'])) {
            throw new InvalidArgumentException('Only owners and super admins may remove recurring private lessons.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A removal reason is required.');
        }

        $charge->refresh()->load([
            'coverage.orderItem.order',
            'event',
            'recurringPrivateLesson.user',
            'recurringPrivateLesson.student',
            'recurringPrivateLesson.course.teachers',
        ]);
        $originalStartsAt = $charge->event->start_time?->toImmutable();

        if (! $originalStartsAt instanceof CarbonInterface) {
            throw new InvalidArgumentException('The lesson start time is unavailable.');
        }

        $this->validatePaymentResolution($charge->status, $paymentResolution);
        $storeCreditAmount = 0;
        $stripeRefundAmount = 0;

        DB::transaction(function () use (
            $charge,
            $removedBy,
            $reason,
            $paymentResolution,
            &$storeCreditAmount,
            &$stripeRefundAmount,
        ): void {
            $lockedCharge = RecurringPrivateLessonCharge::query()
                ->with(['event', 'product', 'recurringPrivateLesson.user'])
                ->lockForUpdate()
                ->findOrFail($charge->id);

            if (! in_array($lockedCharge->status, [
                RecurringPrivateLessonChargeStatus::Scheduled,
                RecurringPrivateLessonChargeStatus::Billed,
                RecurringPrivateLessonChargeStatus::Paid,
            ], true) || $lockedCharge->event->isCancelled()) {
                throw new InvalidArgumentException('Only active scheduled, billed, or paid lessons may be removed.');
            }

            $this->validatePaymentResolution($lockedCharge->status, $paymentResolution);
            $billingPeriodId = $lockedCharge->recurring_private_lesson_billing_period_id;

            if ($lockedCharge->status === RecurringPrivateLessonChargeStatus::Scheduled) {
                $lockedCharge->product?->update(['is_active' => false]);
                $lockedCharge->product?->cartItems()->delete();
                $lockedCharge->event->delete();

                RecurringPrivateLessonBillingPeriod::query()
                    ->whereKey($billingPeriodId)
                    ->whereDoesntHave('charges')
                    ->delete();

                return;
            }

            $lockedCharge->product?->cartItems()->delete();

            if ($lockedCharge->status === RecurringPrivateLessonChargeStatus::Paid) {
                $coverage = $this->lockedActiveCoverage($lockedCharge);

                if ($paymentResolution === RecurringPrivateLessonResolutionType::Refund) {
                    $stripeRefundAmount = $this->refundStripePayment($coverage);
                }

                if ($paymentResolution === RecurringPrivateLessonResolutionType::Credit) {
                    $storeCreditAmount = $coverage->netPaidAmount();
                    $this->issuePrivateLessonCredit(
                        $lockedCharge,
                        $coverage,
                        $removedBy,
                        $reason,
                        $storeCreditAmount,
                    );
                } else {
                    $storeCreditAmount = $coverage->restorableCreditAmount();
                    $this->issuePrivateLessonCredit(
                        $lockedCharge,
                        $coverage,
                        $removedBy,
                        $reason,
                        $storeCreditAmount,
                    );
                }

                $coverage->update([
                    'status' => $paymentResolution === RecurringPrivateLessonResolutionType::Credit
                        ? RecurringPrivateLessonCoverageStatus::Credited
                        : RecurringPrivateLessonCoverageStatus::Refunded,
                ]);
            }

            $lockedCharge->event->update([
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $removedBy->id,
            ]);

            if ($lockedCharge->status === RecurringPrivateLessonChargeStatus::Paid) {
                $lockedCharge->update([
                    'status' => $paymentResolution === RecurringPrivateLessonResolutionType::Credit
                        ? RecurringPrivateLessonChargeStatus::Credited
                        : RecurringPrivateLessonChargeStatus::Refunded,
                    'resolved_at' => now(),
                    'resolved_by_user_id' => $removedBy->id,
                    'resolution_type' => $paymentResolution,
                    'resolution_note' => $reason,
                ]);
            }
        });

        $this->emails->removed(
            $charge,
            $originalStartsAt,
            $reason,
            $paymentResolution,
            $storeCreditAmount,
            $stripeRefundAmount,
        );
    }

    private function validatePaymentResolution(
        RecurringPrivateLessonChargeStatus $status,
        ?RecurringPrivateLessonResolutionType $paymentResolution,
    ): void {
        if ($status === RecurringPrivateLessonChargeStatus::Paid && $paymentResolution === null) {
            throw new InvalidArgumentException('Choose whether to issue credit or refund the paid lesson.');
        }

        if ($status !== RecurringPrivateLessonChargeStatus::Paid && $paymentResolution !== null) {
            throw new InvalidArgumentException('Only paid lessons require a credit or refund choice.');
        }
    }

    private function refundStripePayment(RecurringPrivateLessonCoverage $coverage): int
    {
        $stripeRefundAmount = $coverage->refundableStripeAmount();

        if ($stripeRefundAmount <= 0) {
            return 0;
        }

        if ($coverage->stripe_refund_id !== null) {
            return $stripeRefundAmount;
        }

        $coverage->loadMissing('orderItem.order');

        $paymentIntentId = $coverage->orderItem?->order?->stripe_payment_intent_id;

        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            throw new InvalidArgumentException('The Stripe payment for this lesson could not be found.');
        }

        $refund = $this->stripeService->refundPaymentIntent(
            $paymentIntentId,
            $stripeRefundAmount,
            idempotencyKey: $this->refundIdempotencyKey($coverage, $paymentIntentId),
        );

        if (blank($refund->id)) {
            throw new UnexpectedValueException('Stripe did not return a refund identifier.');
        }

        $coverage->update(['stripe_refund_id' => $refund->id]);

        return $stripeRefundAmount;
    }

    private function refundIdempotencyKey(
        RecurringPrivateLessonCoverage $coverage,
        string $paymentIntentId,
    ): string {
        return "recurring-private-lesson-coverage-{$coverage->id}-refund-".hash('sha256', $paymentIntentId);
    }

    private function lockedActiveCoverage(
        RecurringPrivateLessonCharge $charge,
    ): RecurringPrivateLessonCoverage {
        $coverage = RecurringPrivateLessonCoverage::query()
            ->where('recurring_private_lesson_charge_id', $charge->id)
            ->where('status', RecurringPrivateLessonCoverageStatus::Active)
            ->lockForUpdate()
            ->first();

        if (! $coverage instanceof RecurringPrivateLessonCoverage) {
            throw new InvalidArgumentException('Active payment coverage could not be found for this lesson.');
        }

        return $coverage;
    }

    private function issuePrivateLessonCredit(
        RecurringPrivateLessonCharge $charge,
        RecurringPrivateLessonCoverage $coverage,
        User $removedBy,
        string $reason,
        int $amount,
    ): void {
        if ($amount <= 0) {
            return;
        }

        $this->creditLedger->issue(
            recipient: $charge->recurringPrivateLesson->user,
            amount: $amount,
            description: 'Recurring private lesson cancellation: '.$reason,
            issuer: $removedBy,
            source: $coverage,
            transactionType: CreditTransactionType::Refund,
        );
    }
}
