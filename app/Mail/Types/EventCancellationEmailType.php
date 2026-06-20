<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class EventCancellationEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'event-cancellation',
            names: ['en' => 'Event Cancellation'],
            description: 'Sent to event attendees when an event is cancelled.',
            category: 'handcrafted',
            subjects: ['en' => 'Cancelled: {{ event.name }}'],
            bodies: ['en' => <<<'HTML'
                <p>The following event has been cancelled:</p>
                {{ slot.event-details }}
                <p><strong>Reason:</strong> {{ cancellation.reason }}</p>
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('event.name', 'Event name', example: 'Ballet Class'),
                new Token('cancellation.reason', 'Cancellation reason', example: 'The studio is closed due to severe weather.'),
            ],
            slots: [
                new SystemSlot(
                    key: 'event-details',
                    label: 'Event details',
                    previewHtml: '<p><strong>Ballet Class</strong><br>June 19, 2026 from 5:00 PM–6:00 PM</p>',
                ),
            ],
        );
    }
}
