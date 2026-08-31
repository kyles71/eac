<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Models\RecurringPrivateLessonCharge;
use App\Services\Mail\RecurringPrivateLessonContentService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class SendRecurringPrivateLessonBillingSummary
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private RecurringPrivateLessonContentService $content,
    ) {}

    /** @return array{lessons: int, email_queued: bool} */
    public function handle(?CarbonInterface $dateTime = null, bool $force = false): array
    {
        $displayTimezone = (string) config('app.display_timezone', 'America/New_York');
        $today = CarbonImmutable::instance($dateTime ?? now())
            ->timezone($displayTimezone)
            ->startOfDay();

        if (! $force && ! $today->isSameDay($today->endOfMonth()->subWeek())) {
            return ['lessons' => 0, 'email_queued' => false];
        }

        $nextMonth = $today->addMonthNoOverflow()->startOfMonth();
        $storageTimezone = (string) config('app.timezone');
        $startsAt = $nextMonth->timezone($storageTimezone);
        $endsAt = $nextMonth->endOfMonth()->endOfDay()->timezone($storageTimezone);
        $charges = RecurringPrivateLessonCharge::query()
            ->where('status', RecurringPrivateLessonChargeStatus::Scheduled)
            ->whereHas(
                'recurringPrivateLesson',
                fn (Builder $query): Builder => $query->where(
                    'status',
                    RecurringPrivateLessonStatus::Active,
                ),
            )
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->whereBetween('start_time', [$startsAt, $endsAt]))
            ->with([
                'event',
                'recurringPrivateLesson.student',
                'recurringPrivateLesson.course.teachers',
            ])
            ->get()
            ->sortBy('event.start_time')
            ->values();

        if ($charges->isEmpty()) {
            return ['lessons' => 0, 'email_queued' => false];
        }

        $recipient = (string) config('mail.recurring_private_lesson_billing_summary_recipient');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['lessons' => $charges->count(), 'email_queued' => false];
        }

        $payload = $this->content->forBillingSummary($charges, $nextMonth);

        return [
            'lessons' => $charges->count(),
            'email_queued' => $this->managedEmail->handle(
                recipients: $recipient,
                emailTypeKey: 'recurring-private-lesson-billing-summary',
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            ),
        ];
    }
}
