<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Actions\Mail\SendRecurringPrivateLessonEmail;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RescheduleRecurringPrivateLessonCharge
{
    public function __construct(private SendRecurringPrivateLessonEmail $emails) {}

    public function handle(
        RecurringPrivateLessonCharge $charge,
        CarbonInterface $startsAt,
        User $rescheduledBy,
        string $reason,
    ): void {
        $reason = mb_trim($reason);

        if (! $rescheduledBy->hasAnyRole(['owner', 'super_admin'])) {
            throw new InvalidArgumentException('Only owners and super admins may reschedule recurring private lessons.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A rescheduling reason is required.');
        }

        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));
        $newStartsAt = CarbonImmutable::instance($startsAt)
            ->timezone($displayTimezone)
            ->startOfMinute();
        $originalStartsAt = null;
        $oldBillingPeriodId = null;

        DB::transaction(function () use (
            $charge,
            $newStartsAt,
            $reason,
            $rescheduledBy,
            &$originalStartsAt,
            &$oldBillingPeriodId,
        ): void {
            $lockedCharge = RecurringPrivateLessonCharge::query()
                ->with(['event', 'recurringPrivateLesson'])
                ->lockForUpdate()
                ->findOrFail($charge->id);

            if (! in_array($lockedCharge->status, [
                RecurringPrivateLessonChargeStatus::Scheduled,
                RecurringPrivateLessonChargeStatus::Billed,
                RecurringPrivateLessonChargeStatus::Paid,
            ], true)) {
                throw new InvalidArgumentException('Only scheduled, billed, or paid lessons may be rescheduled.');
            }

            if ($lockedCharge->event->isCancelled()
                || $lockedCharge->event->start_time === null
                || $lockedCharge->event->end_time === null) {
                throw new InvalidArgumentException('This lesson cannot be rescheduled.');
            }

            if (! $newStartsAt->isFuture()) {
                throw new InvalidArgumentException('The new lesson time must be in the future.');
            }

            if ($lockedCharge->status !== RecurringPrivateLessonChargeStatus::Paid
                && ! $newStartsAt->gt(now()->addDay())) {
                throw new InvalidArgumentException('Unpaid lessons may only be rescheduled more than 24 hours in advance.');
            }

            $originalStartsAt = $lockedCharge->event->start_time->toImmutable();
            $oldBillingPeriodId = $lockedCharge->recurring_private_lesson_billing_period_id;
            $rescheduleHistory = is_array($lockedCharge->reschedule_history)
                ? $lockedCharge->reschedule_history
                : [];
            $rescheduleHistory[] = [
                'reason' => $reason,
                'previous_starts_at' => $originalStartsAt->toIso8601String(),
                'new_starts_at' => $newStartsAt->toIso8601String(),
                'rescheduled_at' => now()->toIso8601String(),
                'rescheduled_by_user_id' => $rescheduledBy->id,
            ];
            $durationInMinutes = (int) $lockedCharge->event->start_time->diffInMinutes(
                $lockedCharge->event->end_time,
            );
            $storageTimezone = (string) config('app.timezone');

            $lockedCharge->event->update([
                'start_time' => $newStartsAt->timezone($storageTimezone)->toDateTimeString(),
                'end_time' => $newStartsAt->addMinutes($durationInMinutes)
                    ->timezone($storageTimezone)
                    ->toDateTimeString(),
                'reminder_processed_at' => null,
            ]);

            $lockedCharge->refresh()->update([
                'seven_day_reminder_sent_at' => null,
                'two_day_reminder_sent_at' => null,
                'reschedule_history' => $rescheduleHistory,
            ]);

            if ($oldBillingPeriodId !== $lockedCharge->recurring_private_lesson_billing_period_id) {
                RecurringPrivateLessonBillingPeriod::query()
                    ->whereKey($oldBillingPeriodId)
                    ->whereDoesntHave('charges')
                    ->delete();
            }
        });

        $charge->load([
            'event',
            'recurringPrivateLesson.user',
            'recurringPrivateLesson.student',
            'recurringPrivateLesson.course.teachers',
        ]);

        $this->emails->rescheduled(
            $charge,
            $originalStartsAt ?? throw new InvalidArgumentException('The original lesson time is unavailable.'),
            $reason,
        );
    }
}
