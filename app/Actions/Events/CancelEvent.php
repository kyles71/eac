<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Mail\QueueManagedEmail;
use App\Models\Event;
use App\Models\User;
use App\Services\Mail\EventCancellationContentService;
use App\Services\Mail\EventCancellationRecipientsService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class CancelEvent
{
    public function __construct(
        private EventCancellationRecipientsService $recipients,
        private EventCancellationContentService $content,
        private QueueManagedEmail $managedEmail,
    ) {}

    public function handle(Event $event, User $cancelledBy, string $reason, bool $sendEmail): int
    {
        $reason = (string) str($reason)->squish();

        if ($reason === '') {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($event, $cancelledBy, $reason, $sendEmail): int {
            /** @var Event|null $lockedEvent */
            $lockedEvent = Event::query()
                ->lockForUpdate()
                ->find($event->getKey());

            if (! $lockedEvent instanceof Event) {
                throw new InvalidArgumentException('The event could not be found.');
            }

            if ($lockedEvent->isCancelled()) {
                throw new DomainException('This event has already been cancelled.');
            }

            if (! $lockedEvent->canBeCancelledAt()) {
                throw new DomainException('Events with an end time can only be cancelled before they end. Events without an end time can only be cancelled before they start.');
            }

            Gate::forUser($cancelledBy)->authorize('cancel', $lockedEvent);

            $lockedEvent->update([
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $cancelledBy->id,
            ]);

            if (! $sendEmail) {
                return 0;
            }

            $payload = $this->content->for($lockedEvent);
            $queued = 0;

            foreach ($this->recipients->for($lockedEvent) as $recipient) {
                if ($this->managedEmail->handle(
                    recipients: $recipient,
                    emailTypeKey: 'event-cancellation',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                    mailer: 'handcrafted',
                )) {
                    $queued++;
                }
            }

            return $queued;
        });
    }
}
