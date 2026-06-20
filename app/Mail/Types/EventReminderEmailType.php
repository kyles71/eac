<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class EventReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'event-reminder',
            names: ['en' => 'Event Reminder'],
            description: 'Sent two weeks before a standalone event or the first event in a course.',
            category: 'transactional',
            subjects: ['en' => 'Reminder: {{ event.name }} on {{ event.starts_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>This is a reminder that {{ event.name }} is coming up in two weeks.</p>
                {{ slot.event-details }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Jamie'),
                new Token('user.full_name', 'User full name', example: 'Jamie Dancer'),
                new Token('user.email', 'User email address', example: 'jamie@example.com'),
                new Token('student.first_name', 'Student first name', example: 'Alex'),
                new Token('student.full_name', 'Student full name', example: 'Alex Dancer'),
                new Token('event.name', 'Event name', example: 'Ballet Class'),
                new Token('event.starts_at', 'Event start date and time', example: 'July 3, 2026 6:00 PM EDT'),
                new Token('event.ends_at', 'Event end date and time', example: 'July 3, 2026 7:00 PM EDT'),
                new Token('course.name', 'Course name when applicable', example: 'Ballet 2'),
            ],
            slots: [
                new SystemSlot(
                    key: 'event-details',
                    label: 'Event details',
                    previewHtml: '<p>The event name, course, calendar, start time, and end time appear here.</p>',
                ),
            ],
        );
    }
}
