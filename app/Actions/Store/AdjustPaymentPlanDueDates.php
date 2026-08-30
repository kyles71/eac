<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\InstallmentDueDateAdjustment;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\PaymentPlanScheduleEmailAvailabilityService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class AdjustPaymentPlanDueDates
{
    public function __construct(
        private PaymentPlanScheduleEmailAvailabilityService $emailAvailability,
        private SendPaymentPlanScheduleAdjustedEmail $scheduleAdjustedEmail,
    ) {}

    /**
     * @param  array<int|string, mixed>  $dueDates
     * @return array{adjusted: int, customer_notification_status: string, customer_notification_note: ?string}
     */
    public function handle(
        PaymentPlan $paymentPlan,
        User $adjustedBy,
        array $dueDates,
        string $reason,
        bool $confirmWithoutEmail = false,
    ): array {
        $reason = (string) str($reason)->squish();

        if ($reason === '') {
            throw new InvalidArgumentException('A customer-visible reason is required.');
        }

        if (mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('The customer-visible reason may not exceed 2,000 characters.');
        }

        /** @var array{payment_plan: PaymentPlan, adjustment_batch_uuid: string, adjusted: int, notification_available: bool, notification_note: ?string} $adjustment */
        $adjustment = DB::transaction(function () use ($paymentPlan, $adjustedBy, $dueDates, $reason, $confirmWithoutEmail): array {
            /** @var PaymentPlan|null $lockedPaymentPlan */
            $lockedPaymentPlan = PaymentPlan::query()
                ->whereKey($paymentPlan->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedPaymentPlan instanceof PaymentPlan) {
                throw new InvalidArgumentException('The payment plan could not be found.');
            }

            Gate::forUser($adjustedBy)->authorize('adjustDueDates', $lockedPaymentPlan);

            /** @var Collection<int, Installment> $installments */
            $installments = $lockedPaymentPlan->installments()
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();
            $unpaidInstallments = $installments
                ->where('status', '!=', InstallmentStatus::Paid);
            $normalizedDueDates = $this->normalizeDueDates($dueDates);

            if ($normalizedDueDates->keys()->sort()->values()->all() !== $unpaidInstallments->values()->modelKeys()) {
                throw new InvalidArgumentException('A due date is required for every unpaid installment on this payment plan.');
            }

            $tomorrow = now()
                ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
                ->startOfDay()
                ->addDay()
                ->toDateString();

            foreach ($unpaidInstallments as $installment) {
                $newDueDate = $normalizedDueDates->get($installment->id);

                if (! $newDueDate instanceof CarbonImmutable || $newDueDate->toDateString() < $tomorrow) {
                    throw new InvalidArgumentException('Every unpaid installment due date must be tomorrow or later.');
                }
            }

            $previousDueDate = null;

            foreach ($unpaidInstallments as $installment) {
                $dueDate = $normalizedDueDates->get($installment->id);

                if (! $dueDate instanceof CarbonImmutable) {
                    throw new InvalidArgumentException('A due date is required for every unpaid installment on this payment plan.');
                }

                if ($previousDueDate instanceof CarbonImmutable && ! $dueDate->gt($previousDueDate)) {
                    throw new InvalidArgumentException('Installment due dates must remain strictly increasing.');
                }

                $previousDueDate = $dueDate;
            }

            $changes = $unpaidInstallments
                ->filter(fn (Installment $installment): bool => ! $normalizedDueDates
                    ->get($installment->id)?->isSameDay($installment->due_date))
                ->map(fn (Installment $installment): array => [
                    'installment' => $installment,
                    'old_due_date' => $installment->due_date->toDateString(),
                    'new_due_date' => $normalizedDueDates->get($installment->id)?->toDateString(),
                    'previous_status' => $installment->status,
                    'previous_retry_count' => $installment->retry_count,
                ]);

            if ($changes->isEmpty()) {
                throw new DomainException('At least one installment due date must be changed.');
            }

            foreach ($changes as $change) {
                /** @var Installment $installment */
                $installment = $change['installment'];
                $installment->update([
                    'due_date' => $change['new_due_date'],
                    'status' => InstallmentStatus::Pending,
                    'retry_count' => 0,
                    'last_attempted_at' => null,
                    'last_payment_status' => null,
                    'last_failure_reason' => null,
                    'last_failure_code' => null,
                    'past_due_notification_sent_at' => null,
                ]);
            }

            $lockedPaymentPlan->unsetRelation('installments');
            $lockedPaymentPlan->load(['installments', 'order.user']);
            $availability = $this->emailAvailability->for($lockedPaymentPlan, $reason);

            if (! $availability['available'] && ! $confirmWithoutEmail) {
                throw new DomainException(
                    $availability['reason'].' Confirm that the schedule should be saved without emailing the customer.'
                );
            }

            $adjustmentBatchUuid = (string) Str::uuid();
            $notificationStatus = $availability['available'] ? 'Pending' : 'Skipped';

            foreach ($changes as $change) {
                /** @var Installment $installment */
                $installment = $change['installment'];
                $installment->dueDateAdjustments()->create([
                    'adjustment_batch_uuid' => $adjustmentBatchUuid,
                    'adjusted_by_user_id' => $adjustedBy->id,
                    'old_due_date' => $change['old_due_date'],
                    'new_due_date' => $change['new_due_date'],
                    'previous_status' => $change['previous_status'],
                    'previous_retry_count' => $change['previous_retry_count'],
                    'reason' => $reason,
                    'customer_notification_status' => $notificationStatus,
                    'customer_notification_note' => $availability['reason'],
                ]);
            }

            return [
                'payment_plan' => $lockedPaymentPlan,
                'adjustment_batch_uuid' => $adjustmentBatchUuid,
                'adjusted' => $changes->count(),
                'notification_available' => $availability['available'],
                'notification_note' => $availability['reason'],
            ];
        }, attempts: 3);

        if (! $adjustment['notification_available']) {
            return [
                'adjusted' => $adjustment['adjusted'],
                'customer_notification_status' => 'Skipped',
                'customer_notification_note' => $adjustment['notification_note'],
            ];
        }

        try {
            $queued = $this->scheduleAdjustedEmail->handle($adjustment['payment_plan'], $reason);
            $status = $queued ? 'Queued' : 'Failed';
            $note = $queued ? null : 'The customer email was available during preflight but could not be queued.';
        } catch (Throwable $exception) {
            report($exception);
            $status = 'Failed';
            $note = 'The customer email was available during preflight but could not be queued.';
        }

        InstallmentDueDateAdjustment::query()
            ->where('adjustment_batch_uuid', $adjustment['adjustment_batch_uuid'])
            ->update([
                'customer_notification_status' => $status,
                'customer_notification_note' => $note,
            ]);

        return [
            'adjusted' => $adjustment['adjusted'],
            'customer_notification_status' => $status,
            'customer_notification_note' => $note,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $dueDates
     * @return SupportCollection<int, CarbonImmutable>
     */
    private function normalizeDueDates(array $dueDates): SupportCollection
    {
        return collect($dueDates)->mapWithKeys(function (mixed $dueDate, int|string $installmentId): array {
            if (! is_numeric($installmentId) || ! is_string($dueDate)) {
                throw new InvalidArgumentException('The installment due date submission is invalid.');
            }

            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $dueDate);
            } catch (Throwable) {
                $date = null;
            }

            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $dueDate) {
                throw new InvalidArgumentException('Installment due dates must use the YYYY-MM-DD format.');
            }

            return [(int) $installmentId => $date];
        });
    }
}
