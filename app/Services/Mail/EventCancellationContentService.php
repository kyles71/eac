<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Event;

final readonly class EventCancellationContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(Event $event): array
    {
        $event->loadMissing(['calendar', 'course']);

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'event.name' => $event->name,
                'cancellation.reason' => (string) $event->cancellation_reason,
            ],
            'slots' => [
                'event-details' => view('mail.event-cancellation-details', [
                    'event' => $event,
                    'displayTimezone' => (string) config('app.display_timezone', config('app.timezone')),
                ])->render(),
            ],
        ];
    }
}
