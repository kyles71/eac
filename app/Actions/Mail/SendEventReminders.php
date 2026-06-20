<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\Course;
use App\Models\Event;
use App\Services\Mail\EventReminderContentService;
use App\Services\Mail\EventReminderRecipientsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class SendEventReminders
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private EventReminderRecipientsService $recipients,
        private EventReminderContentService $content,
    ) {}

    /** @return array{events_processed: int, emails_queued: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        [$targetStartsAt, $targetEndsAt] = $this->targetWindow($dateTime);
        $eventsProcessed = 0;
        $emailsQueued = 0;

        Event::query()
            ->whereNull('course_id')
            ->whereNull('cancelled_at')
            ->whereNull('reminder_processed_at')
            ->whereBetween('start_time', [$targetStartsAt, $targetEndsAt])
            ->lazyById()
            ->each(function (Event $event) use (&$eventsProcessed, &$emailsQueued): void {
                $emailsQueued += $this->processEvent($event);
                $eventsProcessed++;
            });

        Course::query()
            ->whereNull('event_reminder_processed_at')
            ->whereHas('events', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->whereBetween('start_time', [$targetStartsAt, $targetEndsAt]))
            ->lazyById()
            ->each(function (Course $course) use ($targetStartsAt, $targetEndsAt, &$eventsProcessed, &$emailsQueued): void {
                $firstEvent = $course->events()
                    ->whereNull('cancelled_at')
                    ->whereNotNull('start_time')
                    ->oldest('start_time')
                    ->oldest('id')
                    ->first();

                if (! $firstEvent instanceof Event
                    || $firstEvent->start_time->lt($targetStartsAt)
                    || $firstEvent->start_time->gt($targetEndsAt)) {
                    return;
                }

                $emailsQueued += $this->processEvent($firstEvent);
                $course->update(['event_reminder_processed_at' => now()]);
                $eventsProcessed++;
            });

        return [
            'events_processed' => $eventsProcessed,
            'emails_queued' => $emailsQueued,
        ];
    }

    private function processEvent(Event $event): int
    {
        $queued = 0;
        $createdBeforeCutoff = $event->created_at?->lte($event->start_time->copy()->subWeeks(2)) ?? false;

        if ($createdBeforeCutoff) {
            foreach ($this->recipients->for($event) as $recipient) {
                $payload = $this->content->for($event, $recipient['user'], $recipient['student']);

                if ($this->managedEmail->handle(
                    recipients: $recipient['emails'],
                    emailTypeKey: 'event-reminder',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                )) {
                    $queued++;
                }
            }
        }

        $event->update(['reminder_processed_at' => now()]);

        return $queued;
    }

    /** @return array{CarbonInterface, CarbonInterface} */
    private function targetWindow(?CarbonInterface $dateTime): array
    {
        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));
        $storageTimezone = (string) config('app.timezone');
        $targetDate = CarbonImmutable::instance($dateTime ?? now())
            ->setTimezone($displayTimezone)
            ->addWeeks(2);

        return [
            $targetDate->startOfDay()->setTimezone($storageTimezone),
            $targetDate->endOfDay()->setTimezone($storageTimezone),
        ];
    }
}
