<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Course;
use App\Models\Event;
use App\Models\StudentCommunication;
use App\Models\User;

final readonly class StudentCommunicationContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(StudentCommunication $communication): array
    {
        $communication->loadMissing(['author', 'event.course', 'student']);
        $event = $communication->event;
        $author = $communication->author;
        $student = $communication->student;
        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'communication.date' => $communication->occurred_at
                    ->timezone($displayTimezone)
                    ->format('F j, Y g:i A T'),
                'communication.note' => $communication->note,
                'communication.type' => $communication->type->getLabel(),
                'first_aid.type' => $communication->first_aid_type?->getLabel() ?? '',
                'event.name' => $event instanceof Event ? $event->name : 'No event selected',
                'event.starts_at' => $event?->start_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
                'event.ends_at' => $event?->end_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
                'event.course_name' => $event?->course instanceof Course ? $event->course->name : '',
                'event.context_name' => $event?->course instanceof Course
                    ? $event->course->name
                    : ($event?->name ?? 'No course selected'),
                'teacher.first_name' => $author instanceof User ? $author->first_name : '',
                'teacher.full_name' => $author instanceof User ? $author->full_name : '',
                'teacher.email' => $author instanceof User ? $author->email : '',
                'student.first_name' => $student->first_name,
                'student.full_name' => $student->fullName,
                'stop_light.color' => $communication->stop_light_color?->getLabel() ?? '',
            ],
            'slots' => [],
        ];
    }
}
