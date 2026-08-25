<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class EventSubstituteRequestEmailType implements EmailTypeContract
{
    /** @return list<Token> */
    public static function tokens(): array
    {
        return [
            new Token('app.name', 'Application name', example: 'EAC'),
            new Token('recipient.first_name', 'Recipient first name', example: 'Jamie'),
            new Token('recipient.full_name', 'Recipient full name', example: 'Jamie Teacher'),
            new Token('teacher.full_name', 'Requested teacher full name', example: 'Jamie Teacher'),
            new Token('requester.full_name', 'Requesting staff member', example: 'Alex Owner'),
            new Token('event.name', 'Event name', example: 'Ballet 2 Class'),
            new Token('event.starts_at', 'Event start date and time', example: 'August 12, 2026 6:00 PM EDT'),
            new Token('event.ends_at', 'Event end date and time', example: 'August 12, 2026 7:00 PM EDT'),
            new Token('course.name', 'Course name when applicable', example: 'Ballet 2'),
            new Token('request.reason', 'Optional request or replacement reason', example: 'The regular teacher is unavailable.'),
        ];
    }

    /** @return list<SystemSlot> */
    public static function slots(): array
    {
        return [
            new SystemSlot(
                key: 'event-details',
                label: 'Event details',
                previewHtml: '<p>The event schedule and course appear here.</p>',
            ),
            new SystemSlot(
                key: 'action',
                label: 'Review request button',
                previewHtml: '<p><a href="#">Review Substitute Request</a></p>',
            ),
        ];
    }

    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'event-substitute-request',
            names: ['en' => 'Event Substitute Request'],
            description: 'Sent to a teacher when they are requested to substitute for an event.',
            category: 'transactional',
            subjects: ['en' => 'Substitute request: {{ event.name }} on {{ event.starts_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ recipient.first_name }},</p>
                <p>{{ requester.full_name }} has requested that you substitute for the following event:</p>
                {{ slot.event-details }}
                <p>{{ request.reason }}</p>
                {{ slot.action }}
                HTML],
            tokens: self::tokens(),
            slots: self::slots(),
        );
    }
}
