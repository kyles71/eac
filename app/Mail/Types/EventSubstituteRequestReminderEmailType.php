<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class EventSubstituteRequestReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'event-substitute-request-reminder',
            names: ['en' => 'Event Substitute Request Reminder'],
            description: 'Sent once to both parties when a substitute request remains unanswered.',
            category: 'transactional',
            subjects: ['en' => 'Awaiting response: {{ event.name }} substitute request'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ recipient.first_name }},</p>
                <p>This substitute request has been awaiting a response for {{ request.age_hours }} hours.</p>
                {{ slot.event-details }}
                {{ slot.action }}
                HTML],
            tokens: [
                ...EventSubstituteRequestEmailType::tokens(),
                new Token('recipient.role', 'Recipient context', example: 'requested substitute'),
                new Token('request.age_hours', 'Whole hours since the request', example: '48'),
            ],
            slots: EventSubstituteRequestEmailType::slots(),
        );
    }
}
