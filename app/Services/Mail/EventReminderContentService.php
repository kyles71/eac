<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Course;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;

final readonly class EventReminderContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(Event $event, ?User $user, ?Student $student): array
    {
        $event->loadMissing(['calendar', 'course']);
        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user instanceof User ? $user->first_name : '',
                'user.full_name' => $user instanceof User ? mb_trim("{$user->first_name} {$user->last_name}") : '',
                'user.email' => $user instanceof User ? $user->email : '',
                'student.first_name' => $student instanceof Student ? $student->first_name : '',
                'student.full_name' => $student instanceof Student ? mb_trim("{$student->first_name} {$student->last_name}") : '',
                'event.name' => $event->name,
                'event.starts_at' => $event->start_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
                'event.ends_at' => $event->end_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
                'course.name' => $event->course instanceof Course ? $event->course->name : '',
            ],
            'slots' => [
                'event-details' => view('mail.event-reminder-details', [
                    'event' => $event,
                    'displayTimezone' => $displayTimezone,
                ])->render(),
            ],
        ];
    }
}
