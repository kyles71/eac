<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class EventSubstituteRemovedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'event-substitute-removed',
            names: ['en' => 'Event Substitute Removed'],
            description: 'Sent when a confirmed substitute is removed or replaced before an event ends.',
            category: 'transactional',
            subjects: ['en' => 'Substitute assignment changed: {{ event.name }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ recipient.first_name }},</p>
                <p>You are no longer assigned as the substitute for the following event:</p>
                {{ slot.event-details }}
                <p><strong>Reason:</strong> {{ removal.reason }}</p>
                HTML],
            tokens: [
                ...EventSubstituteRequestEmailType::tokens(),
                new Token('removal.reason', 'Reason the assignment ended', example: 'A different substitute will cover the event.'),
            ],
            slots: [EventSubstituteRequestEmailType::slots()[0]],
        );
    }
}
