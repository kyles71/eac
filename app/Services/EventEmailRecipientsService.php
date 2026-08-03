<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;

final readonly class EventEmailRecipientsService
{
    public function __construct(
        private CourseEmailRecipientsService $courseRecipients,
    ) {}

    /**
     * @return array<int, Student|User|string>
     */
    public function forEvent(Event $event): array
    {
        if ($event->course_id !== null) {
            $event->loadMissing('course');

            return $event->course === null
                ? []
                : $this->courseRecipients->forCourse($event->course);
        }

        return EventAttendee::query()
            ->with('attendee')
            ->where('event_id', $event->id)
            ->whereIn('attendee_type', [
                (new Student)->getMorphClass(),
                (new User)->getMorphClass(),
            ])
            ->get()
            ->map(fn (EventAttendee $attendance): mixed => $attendance->attendee)
            ->filter(fn (mixed $attendee): bool => $attendee instanceof Student || $attendee instanceof User)
            ->sortBy([
                ['first_name', 'asc'],
                ['last_name', 'asc'],
            ])
            ->values()
            ->all();
    }
}
