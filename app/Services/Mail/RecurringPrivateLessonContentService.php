<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Filament\User\Pages\Billing;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class RecurringPrivateLessonContentService
{
    /**
     * @param  Collection<int, RecurringPrivateLessonCharge>  $charges
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function forBillingSummary(Collection $charges, CarbonInterface $month): array
    {
        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'billing.month' => $month->format('F Y'),
                'billing.lesson_count' => (string) $charges->count(),
                'billing.total' => format_money((int) $charges->sum('amount')),
            ],
            'slots' => [
                'private-lesson-billing-summary' => view(
                    'mail.recurring-private-lesson-billing-summary',
                    ['charges' => $charges],
                )->render(),
            ],
        ];
    }

    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function forBillingPeriod(RecurringPrivateLessonBillingPeriod $billingPeriod): array
    {
        $billingPeriod->loadMissing([
            'recurringPrivateLesson.user',
            'recurringPrivateLesson.student',
            'recurringPrivateLesson.course',
            'charges.event',
        ]);
        $charges = $billingPeriod->charges
            ->reject(fn (RecurringPrivateLessonCharge $charge): bool => $charge->status === RecurringPrivateLessonChargeStatus::Cancelled)
            ->sortBy('event.start_time');
        $payableCharges = $charges->where('status', RecurringPrivateLessonChargeStatus::Billed);
        $series = $billingPeriod->recurringPrivateLesson;

        return [
            'tokens' => [
                ...$this->seriesTokens($billingPeriod->recurringPrivateLesson),
                'billing.month' => $billingPeriod->period_start->format('F Y'),
                'billing.total' => format_money((int) $payableCharges->sum('amount')),
                'billing.lesson_count' => (string) $payableCharges->count(),
                'lesson.starts_at' => '',
                'lesson.amount' => '',
                'lesson.status' => '',
                'reminder.days' => '',
            ],
            'slots' => [
                'private-lesson-details' => view('mail.recurring-private-lesson-details', [
                    'series' => $series,
                    'charges' => $charges,
                    'paymentUrl' => Billing::getUrl(['tab' => 'private-lessons'], panel: 'user'),
                ])->render(),
            ],
        ];
    }

    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function forCharge(RecurringPrivateLessonCharge $charge, int $reminderDays = 0): array
    {
        $charge->loadMissing([
            'event',
            'recurringPrivateLesson.user',
            'recurringPrivateLesson.student',
            'recurringPrivateLesson.course',
        ]);
        $series = $charge->recurringPrivateLesson;
        $startsAt = $charge->event->start_time?->timezone(
            (string) config('app.display_timezone', config('app.timezone')),
        );

        return [
            'tokens' => [
                ...$this->seriesTokens($series),
                'billing.month' => $startsAt?->format('F Y') ?? '',
                'billing.total' => format_money($charge->amount),
                'billing.lesson_count' => '1',
                'lesson.starts_at' => $startsAt?->format('F j, Y \a\t g:i A') ?? '',
                'lesson.amount' => format_money($charge->amount),
                'lesson.status' => $charge->status->getLabel(),
                'reminder.days' => (string) $reminderDays,
            ],
            'slots' => [
                'private-lesson-details' => view('mail.recurring-private-lesson-details', [
                    'series' => $series,
                    'charges' => collect([$charge]),
                    'paymentUrl' => Billing::getUrl(['tab' => 'private-lessons'], panel: 'user'),
                ])->render(),
            ],
        ];
    }

    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function forManagedChange(
        RecurringPrivateLessonCharge $charge,
        CarbonInterface $previousStartsAt,
        string $reason,
        string $paymentResolution = '',
    ): array {
        $charge->loadMissing([
            'event',
            'recurringPrivateLesson.user',
            'recurringPrivateLesson.student',
            'recurringPrivateLesson.course',
        ]);
        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));
        $startsAt = $charge->event->start_time?->timezone($displayTimezone);

        return [
            'tokens' => [
                ...$this->seriesTokens($charge->recurringPrivateLesson),
                'lesson.previous_starts_at' => $previousStartsAt->timezone($displayTimezone)
                    ->format('F j, Y \a\t g:i A'),
                'lesson.starts_at' => $startsAt?->format('F j, Y \a\t g:i A') ?? '',
                'lesson.amount' => format_money($charge->amount),
                'lesson.payment_resolution' => $paymentResolution,
                'change.reason' => $reason,
            ],
            'slots' => [],
        ];
    }

    /** @return array<string, string> */
    private function seriesTokens(RecurringPrivateLesson $series): array
    {
        return [
            'app.name' => (string) config('app.name'),
            'user.first_name' => $series->user->first_name,
            'user.full_name' => $series->user->displayName(),
            'student.full_name' => $series->student->displayName(),
            'course.name' => $series->course->name,
        ];
    }
}
